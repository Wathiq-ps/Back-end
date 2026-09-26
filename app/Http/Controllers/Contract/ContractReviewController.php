<?php

namespace App\Http\Controllers\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\RequestModificationRequest;
use App\Http\Requests\Contract\ResolveFindingRequest;
use App\Http\Requests\Contract\StoreContractVersionRequest;
use App\Http\Resources\ContractResource;
use App\Models\AnalysisFinding;
use App\Models\Contract;
use App\Services\ContractReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The assigned lawyer's review of an analysed contract. Each action answers
 * with the contract as show() would, so a client can redraw from the reply.
 */
class ContractReviewController extends Controller
{
    public function __construct(private readonly ContractReviewService $review) {}

    public function resolveFinding(ResolveFindingRequest $request, Contract $contract, AnalysisFinding $finding): JsonResponse
    {
        $this->review->resolveFinding(
            $request->user(),
            $contract,
            $finding,
            $request->validated('resolution'),
            $request->validated('note'),
        );

        return $this->contract($contract, __('Finding updated.'));
    }

    public function storeVersion(StoreContractVersionRequest $request, Contract $contract): JsonResponse
    {
        $this->review->saveVersion($request->user(), $contract, $request->edits(), $request->validated('change_note'));

        return $this->contract($contract, __('The contract was updated.'), 201);
    }

    public function approve(Request $request, Contract $contract): JsonResponse
    {
        $this->review->approve($request->user(), $contract);

        return $this->contract($contract, __('The contract was approved.'));
    }

    public function requestModification(RequestModificationRequest $request, Contract $contract): JsonResponse
    {
        $this->review->requestModification($request->user(), $contract, $request->validated('reason'));

        return $this->contract($contract, __('The contract was sent back for modification.'));
    }

    private function contract(Contract $contract, string $message, int $status = 200): JsonResponse
    {
        $contract = $contract->fresh(['property', 'latestAiJob', 'currentVersion.clauses', 'latestAnalysis.findings']);

        return response()->json(['message' => $message, 'data' => new ContractResource($contract)], $status);
    }
}
