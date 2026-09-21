<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SubmitPropertyRequestRequest;
use App\Http\Resources\PropertyRequestResource;
use App\Services\PropertyRequestService;
use Illuminate\Http\JsonResponse;

class PropertyRequestController extends Controller
{
    public function __construct(private readonly PropertyRequestService $requests) {}

    public function store(SubmitPropertyRequestRequest $request, string $id): JsonResponse
    {
        $propertyRequest = $this->requests->submit(
            $request->user(),
            $id,
            $request->validated(),
        );

        return response()->json([
            'message' => __('Your request was submitted and is pending review.'),
            'data' => new PropertyRequestResource($propertyRequest),
        ], 201);
    }
}
