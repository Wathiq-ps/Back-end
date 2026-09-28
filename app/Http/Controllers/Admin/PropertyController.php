<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectOwnershipDocumentRequest;
use App\Http\Resources\Admin\OwnershipDocumentResource;
use App\Http\Resources\Admin\PropertyResource;
use App\Models\OwnershipDocument;
use App\Models\Property;
use App\Services\OwnershipDocumentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * FR-2.2/FR-2.4, UC-004 — the admin side of the add-property flow.
 *
 * Two queues live here, because the thing that needs a decision is the
 * *ownership document*, not the listing: documents() is what an admin works
 * through, and approving/rejecting one moves the parent property on its own
 * (see OwnershipDocumentService::syncPropertyStatus). index()/show() are the
 * read side — the whole catalogue regardless of status, which no other
 * endpoint exposes.
 */
class PropertyController extends Controller
{
    public function __construct(private readonly OwnershipDocumentService $documents) {}

    /**
     * app.property_status is a native Postgres enum — comparing the column
     * against a value outside this list isn't "no match", it's a DB-level type
     * error (500), so an unrecognised ?status must be rejected before it ever
     * reaches the query.
     */
    private const PROPERTY_STATUSES = [
        'draft',
        'pending_verification',
        'published',
        'under_contract',
        'sold',
        'rented',
        'rejected',
    ];

    /**
     * app.listing_type. Same reasoning as PROPERTY_STATUSES — and note this
     * is why ?listing_type can't be matched with lower(), the way ?type and
     * ?city are: there is no lower(app.listing_type), only lower(text).
     */
    private const LISTING_TYPES = ['sale', 'rent'];

    /**
     * app.verification_status. Same reasoning as PROPERTY_STATUSES.
     */
    private const DOCUMENT_STATUSES = ['pending', 'under_review', 'approved', 'rejected'];

    private const PER_PAGE = 20;

    /**
     * Every listing in the system, newest first. Defaults to
     * ?status=pending_verification — what is actually waiting on this admin —
     * with ?status=all for the full catalogue or any single status to narrow
     * it. ?city / ?district / ?reference narrow further; reference is a
     * prefix match so a partially typed one still finds the listing.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');

        $query = Property::query()
            ->with(['owner', 'media', 'amenities', 'ownershipDocuments.reviewer'])
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->whereRaw('lower(type) = lower(?)', [$type]);
        }

        if ($listingType = $request->query('listing_type')) {
            // Lower-cased here rather than in SQL: the labels are all
            // lowercase, so normalising the input keeps ?listing_type=Rent
            // working without asking postgres to lower() an enum.
            $listingType = strtolower($listingType);
            abort_unless(in_array($listingType, self::LISTING_TYPES, true), 422, 'Invalid listing_type filter.');
            $query->where('listing_type', $listingType);
        }

        if ($status !== 'all') {
            abort_unless(in_array($status, self::PROPERTY_STATUSES, true), 422, 'Invalid status filter.');
            $query->where('status', $status);
        }

        if ($city = $request->query('city')) {
            $query->whereRaw('lower(city) = lower(?)', [$city]);
        }

        if ($district = $request->query('district')) {
            $query->whereRaw('lower(district) = lower(?)', [$district]);
        }

        if ($reference = $request->query('reference')) {
            $query->where('reference', 'ilike', $reference . '%');
        }

        $properties = $query->paginate(self::PER_PAGE);

        return $this->paginated(PropertyResource::collection($properties), $properties);
    }

    public function show(Property $property): JsonResponse
    {
        $property->load(['owner', 'media', 'amenities', 'ownershipDocuments.reviewer']);

        return response()->json(['data' => new PropertyResource($property)]);
    }

    /**
     * The review queue: ownership documents across every listing. Defaults to
     * ?status=queue (pending/under_review) so the admin sees only what needs a
     * decision; ?status=approved|rejected|all for the rest. Oldest first —
     * first submitted, first reviewed.
     */
    public function documents(Request $request): JsonResponse
    {
        $documents = $this->documentQuery($request)->paginate(self::PER_PAGE);

        return $this->paginated(OwnershipDocumentResource::collection($documents), $documents);
    }

    /**
     * The same queue scoped to one listing — what the admin opens from a
     * property's detail view. Accepts the same ?status filter, but defaults to
     * `all`: here the point is the listing's whole document history, not just
     * the open items.
     */
    public function propertyDocuments(Property $property, Request $request): JsonResponse
    {
        $documents = $this->documentQuery($request, 'all')
            ->where('property_id', $property->id)
            ->paginate(self::PER_PAGE);

        return $this->paginated(OwnershipDocumentResource::collection($documents), $documents);
    }

    /**
     * Streams the file through the app rather than a public or pre-signed
     * storage URL — access control here is "is an admin", enforced by the
     * route's own middleware, every time the document is opened.
     */
    public function documentFile(OwnershipDocument $ownershipDocument): HttpResponse
    {
        $file = $this->documents->readDocument($ownershipDocument);

        return response($file['contents'], 200)->header('Content-Type', $file['mime']);
    }

    public function approve(OwnershipDocument $ownershipDocument, Request $request): JsonResponse
    {
        $this->documents->approve($ownershipDocument, $request->user());

        return response()->json([
            'data' => new OwnershipDocumentResource($this->reloaded($ownershipDocument)),
        ]);
    }

    public function reject(OwnershipDocument $ownershipDocument, RejectOwnershipDocumentRequest $request): JsonResponse
    {
        $this->documents->reject($ownershipDocument, $request->user(), $request->validated('reason'));

        return response()->json([
            'data' => new OwnershipDocumentResource($this->reloaded($ownershipDocument)),
        ]);
    }

    /**
     * The decision may have moved the parent property, so it is re-read rather
     * than served from the copy the route binding resolved.
     */
    private function reloaded(OwnershipDocument $document): OwnershipDocument
    {
        return $document->fresh(['property.owner', 'uploader', 'reviewer']);
    }

    /**
     * @return Builder<OwnershipDocument>
     */
    private function documentQuery(Request $request, string $default = 'queue'): Builder
    {
        $status = $request->query('status', $default);

        abort_unless(
            in_array($status, [...self::DOCUMENT_STATUSES, 'queue', 'all'], true),
            422,
            'Invalid status filter.',
        );

        $query = OwnershipDocument::query()
            ->with(['property.owner', 'uploader', 'reviewer'])
            ->orderBy('created_at');

        match ($status) {
            'queue' => $query->whereIn('status', OwnershipDocumentService::OPEN_STATUSES),
            'all' => null,
            default => $query->where('status', $status),
        };

        return $query;
    }

    private function paginated(AnonymousResourceCollection $data, LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
