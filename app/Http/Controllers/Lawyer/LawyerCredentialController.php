<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lawyer\SubmitLawyerCredentialRequest;
use App\Http\Resources\LawyerCredentialResource;
use App\Models\LawyerCredential;
use App\Services\LawyerCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LawyerCredentialController extends Controller
{
    public function __construct(private readonly LawyerCredentialService $credentials) {}

    public function store(SubmitLawyerCredentialRequest $request): JsonResponse
    {
        $credential = $this->credentials->submit(
            $request->user(),
            $request->safe()->except('document'),
            $request->file('document'),
        );

        return response()->json([
            'message' => __('Your credentials were submitted and are pending review.'),
            'credential' => new LawyerCredentialResource($credential),
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $credential = LawyerCredential::find($request->user()->id);

        return response()->json([
            'verified' => $request->user()->isVerifiedLawyer(),
            'credential' => $credential ? new LawyerCredentialResource($credential) : null,
        ]);
    }
}
