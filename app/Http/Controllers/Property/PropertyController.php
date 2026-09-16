<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Http\Resources\PropertySearchResource;
use App\Http\Resources\TopRatedPropertyResource;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    /**
     * app.property_status is a native Postgres enum — comparing the column
     * against a value outside this list isn't "no match", it's a DB-level
     * type error (500), so an unrecognised ?status must be rejected before
     * it ever reaches the query.
     */
    private const STATUSES = ['draft', 'pending_verification', 'published', 'under_contract', 'sold', 'rented', 'rejected'];

    /**
     * app.listing_type is a native Postgres enum — same reasoning as STATUSES.
     */
    private const LISTING_TYPES = ['sale', 'rent'];

    /**
     * Home-page "top rated" widget.
     */
    private const TOP_RATED_LIMIT = 5;

    /**
     * The authenticated owner's own listings, newest first. ?status=draft
     * (etc.) narrows to one status.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Property::query()
            ->where('owner_id', $request->user()->id)
            ->with(['media', 'ownershipDocuments', 'amenities'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            abort_unless(in_array($status, self::STATUSES, true), 422, 'Invalid status filter.');
            $query->where('status', $status);
        }

        $properties = $query->paginate(20);

        return response()->json([
            'data' => PropertyResource::collection($properties),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'total' => $properties->total(),
            ],
        ]);
    }

    /**
     * Public home-page search — no auth required. Only published listings
     * are visible here; an owner's own draft/pending/rejected listings only
     * show up in index() ("My properties"). Mirrors the properties_browse_idx
     * shape: tenant + listing_type + published-only, newest first.
     */
    public function search(Request $request): JsonResponse
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');
        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $query = Property::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->with(['media', 'amenities'])
            ->orderByDesc('published_at');

        if ($listingType = $request->query('listing_type')) {
            abort_unless(in_array($listingType, self::LISTING_TYPES, true), 422, 'Invalid listing_type filter.');
            $query->where('listing_type', $listingType);
        }

        if ($city = $request->query('city')) {
            $query->whereRaw('lower(city) = lower(?)', [$city]);
        }

        if ($district = $request->query('district')) {
            $query->whereRaw('lower(district) = lower(?)', [$district]);
        }

        $properties = $query->paginate(20);

        return response()->json([
            'data' => PropertySearchResource::collection($properties),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'total' => $properties->total(),
            ],
        ]);
    }

    /**
     * Public home-page "top rated" — up to 5 published listings, rated ones
     * first (highest average rating first). The rating join is inner, not
     * left: a property with no ratings has nothing to rank it by, so it
     * never displaces a rated one. But the widget still needs content on a
     * near-empty marketplace, so once rated listings run out the remaining
     * slots are backfilled with the newest published listings instead of
     * leaving the response short (or, with zero ratings anywhere, blank).
     */
    public function topRated(Request $request): JsonResponse
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');
        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $rated = Property::query()
            ->select('properties.*')
            ->selectRaw('avg(property_ratings.score) as average_rating')
            ->selectRaw('count(property_ratings.id) as ratings_count')
            ->join('property_ratings', 'property_ratings.property_id', '=', 'properties.id')
            ->where('properties.tenant_id', $tenantId)
            ->where('properties.status', 'published')
            ->with(['media', 'amenities'])
            ->groupBy('properties.id')
            ->orderByDesc('average_rating')
            ->orderByDesc('ratings_count')
            ->orderByDesc('properties.published_at')
            ->limit(self::TOP_RATED_LIMIT)
            ->get();

        $properties = $rated;
        $remaining = self::TOP_RATED_LIMIT - $rated->count();

        if ($remaining > 0) {
            $newest = Property::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'published')
                ->whereNotIn('id', $rated->pluck('id'))
                ->with(['media', 'amenities'])
                ->orderByDesc('published_at')
                ->limit($remaining)
                ->get();

            $properties = $rated->concat($newest);
        }

        return response()->json([
            'data' => TopRatedPropertyResource::collection($properties),
        ]);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = $this->properties->create(
            $request->user(),
            $request->validated(),
            $request->file('photos'),
            $request->file('ownership_documents'),
        );

        return response()->json([
            'message' => __('Your property was submitted and is pending review.'),
            'property' => new PropertyResource($property),
        ], 201);
    }

    public function update(UpdatePropertyRequest $request, string $id): JsonResponse
    {
        $property = $this->properties->update(
            $request->user(),
            $id,
            $request->safe()->except('photos'),
            $request->file('photos', []),
        );

        return response()->json([
            'property' => new PropertyResource($property),
        ]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $property = $this->properties->publish(
            $request->user(),
            $id,
        );

        return response()->json([
            'property' => new PropertyResource($property),
        ]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $property = $this->properties->suspend(
            $request->user(),
            $id,
        );

        return response()->json([
            'property' => new PropertyResource($property),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->properties->delete(
            $request->user(),
            $id,
        );

        return response()->json([
            'message' => 'Property deleted successfully',
        ]);
    }
}
