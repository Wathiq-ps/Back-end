<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectIdentityDocumentRequest;
use App\Http\Resources\Admin\IdentityDocumentResource;
use App\Models\IdentityDocument;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class IdentityDocumentController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * The review queue. Defaults to pending/under_review so the admin sees
     * what actually needs attention; ?status=approved|rejected|all for the
     * rest.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'queue');

        $query = IdentityDocument::query()->with(['user', 'reviewer'])->orderBy('created_at');

        match ($status) {
            'queue' => $query->whereIn('status', ['pending', 'under_review']),
            'all' => null,
            default => $query->where('status', $status),
        };

        $documents = $query->paginate(20);

        return response()->json([
            'data' => IdentityDocumentResource::collection($documents),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function show(IdentityDocument $identityDocument): JsonResponse
    {
        $identityDocument->load(['user', 'reviewer']);

        return response()->json(['data' => new IdentityDocumentResource($identityDocument)]);
    }

    /**
     * Streams the file through the app rather than a public/pre-signed
     * storage URL — access control here is "is an admin", enforced by the
     * route's own middleware, every time the image is opened.
     */
    public function image(IdentityDocument $identityDocument, string $which): HttpResponse
    {
        $image = $this->kyc->readImage($identityDocument, $which);

        return response($image['contents'], 200)->header('Content-Type', $image['mime']);
    }

    public function approve(IdentityDocument $identityDocument, Request $request): JsonResponse
    {
        $this->kyc->approve($identityDocument, $request->user());

        return response()->json(['data' => new IdentityDocumentResource($identityDocument->fresh(['user', 'reviewer']))]);
    }

    public function reject(IdentityDocument $identityDocument, RejectIdentityDocumentRequest $request): JsonResponse
    {
        $this->kyc->reject($identityDocument, $request->user(), $request->validated('reason'));

        return response()->json(['data' => new IdentityDocumentResource($identityDocument->fresh(['user', 'reviewer']))]);
    }
}
