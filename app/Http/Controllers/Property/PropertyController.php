<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;

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
}
