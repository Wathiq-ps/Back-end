<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
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
