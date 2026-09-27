<?php

namespace App\Http\Controllers\Contract;

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contracts the caller is a party to (owner, beneficiary or lawyer). The AI
 * work is asynchronous: a client polls show() and reads `ai_job.status`.
 */
class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contracts) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $contracts = Contract::query()
            ->where(fn ($q) => $q->where('owner_id', $userId)
                ->orWhere('beneficiary_id', $userId)
                ->orWhere('lawyer_id', $userId))
            ->with(['property', 'latestAiJob'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => ContractResource::collection($contracts),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'last_page' => $contracts->lastPage(),
                'total' => $contracts->total(),
            ],
        ]);
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        if (! $contract->isParty($request->user())) {
            throw AuthorizationFailedException::forbidden();
        }

        $contract->load(['property', 'latestAiJob', 'currentVersion.clauses']);

        // The analysis is the lawyer's working material: its risk score swings
        // between runs, so it is not shown to the parties as a verdict.
        if ($contract->lawyer_id === $request->user()->id) {
            $contract->load('currentAnalysis.findings');
        }

        return response()->json(['data' => new ContractResource($contract)]);
    }

    public function submitForAnalysis(Request $request, Contract $contract): JsonResponse
    {
        $this->contracts->submitForAnalysis($request->user(), $contract);

        return response()->json([
            'message' => __('The contract was submitted for analysis.'),
            'data' => new ContractResource($contract->fresh(['property', 'latestAiJob'])),
        ], 202);
    }

    public function retryGeneration(Request $request, Contract $contract): JsonResponse
    {
        $this->contracts->retryGeneration($request->user(), $contract);

        return response()->json([
            'message' => __('The contract is being drafted again.'),
            'data' => new ContractResource($contract->fresh(['property', 'latestAiJob'])),
        ], 202);
    }
}
