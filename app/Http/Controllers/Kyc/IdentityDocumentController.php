<?php

namespace App\Http\Controllers\Kyc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\SubmitIdentityDocumentRequest;
use App\Http\Resources\IdentityDocumentResource;
use App\Models\IdentityDocument;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityDocumentController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function store(SubmitIdentityDocumentRequest $request): JsonResponse
    {
        $document = $this->kyc->submit(
            $request->user(),
            $request->file('front_image'),
            $request->file('selfie_image'),
            $request->validated('type'),
            $request->validated('document_number'),
            $request->validated('issuing_country_id'),
        );

        return response()->json([
            'message' => __('Your documents were submitted and are pending review.'),
            'document' => new IdentityDocumentResource($document),
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $latest = IdentityDocument::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'verified' => $request->user()->isKycVerified(),
            'latest_document' => $latest ? new IdentityDocumentResource($latest) : null,
        ]);
    }
}
