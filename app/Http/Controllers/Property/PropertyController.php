<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

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
