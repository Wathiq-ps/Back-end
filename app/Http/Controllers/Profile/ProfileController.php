<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profile) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profile->update(
            $request->user(),
            $request->safe()->except('signature_image'),
            $request->file('signature_image'),
        );

        return response()->json([
            'message' => __('Your profile was updated.'),
            'user' => new UserResource($user),
        ]);
    }
}
