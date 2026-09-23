<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectLawyerCredentialRequest;
use App\Http\Resources\Admin\LawyerCredentialResource;
use App\Models\LawyerCredential;
use App\Services\LawyerCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LawyerCredentialController extends Controller
{
    public function __construct(private readonly LawyerCredentialService $credentials) {}

    /**
     * The review queue. Defaults to pending/under_review so the admin sees
     * what actually needs attention; ?status=approved|rejected|all for the
     * rest.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'queue');

        $query = LawyerCredential::query()->with(['user', 'reviewer'])->orderBy('created_at');

        match ($status) {
            'queue' => $query->whereIn('status', ['pending', 'under_review']),
            'all' => null,
            default => $query->where('status', $status),
        };

        $credentials = $query->paginate(20);

        return response()->json([
            'data' => LawyerCredentialResource::collection($credentials),
            'meta' => [
                'current_page' => $credentials->currentPage(),
                'last_page' => $credentials->lastPage(),
                'total' => $credentials->total(),
            ],
        ]);
    }

    public function show(LawyerCredential $lawyerCredential): JsonResponse
    {
        $lawyerCredential->load(['user', 'reviewer']);

        return response()->json(['data' => new LawyerCredentialResource($lawyerCredential)]);
    }

    /**
     * Streams the licence through the app rather than a public/pre-signed
     * storage URL — access control here is "is an admin", enforced by the
     * route's own middleware, every time the file is opened.
     */
    public function document(LawyerCredential $lawyerCredential): HttpResponse
    {
        $document = $this->credentials->readDocument($lawyerCredential);

        return response($document['contents'], 200)->header('Content-Type', $document['mime']);
    }

    public function approve(LawyerCredential $lawyerCredential, Request $request): JsonResponse
    {
        $this->credentials->approve($lawyerCredential, $request->user());

        return response()->json(['data' => new LawyerCredentialResource($lawyerCredential->fresh(['user', 'reviewer']))]);
    }

    public function reject(LawyerCredential $lawyerCredential, RejectLawyerCredentialRequest $request): JsonResponse
    {
        $this->credentials->reject($lawyerCredential, $request->user(), $request->validated('reason'));

        return response()->json(['data' => new LawyerCredentialResource($lawyerCredential->fresh(['user', 'reviewer']))]);
    }
}
