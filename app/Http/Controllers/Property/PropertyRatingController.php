<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRatingRequest;
use App\Http\Resources\PropertyRatingResource;
use App\Services\PropertyRatingService;
use Illuminate\Http\JsonResponse;

class PropertyRatingController extends Controller
{
    public function __construct(private readonly PropertyRatingService $ratings) {}

    public function store(StorePropertyRatingRequest $request, string $id): JsonResponse
    {
        $rating = $this->ratings->create(
            $request->user(),
            $id,
            $request->validated(),
        );

        return response()->json([
            'rating' => new PropertyRatingResource($rating),
        ], 201);
    }
}
