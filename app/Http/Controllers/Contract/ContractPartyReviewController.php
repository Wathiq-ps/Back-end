<?php

namespace App\Http\Controllers\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\RejectContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Services\ContractReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's and beneficiary's review of the contract the lawyer approved.
 * Each action answers with the contract as show() gives it to a party.
 */
class ContractPartyReviewController extends Controller
{
    public function __construct(private readonly ContractReviewService $review) {}

    public function approve(Request $request, Contract $contract): JsonResponse
    {
        $this->review->approveAsParty($request->user(), $contract);

        return $this->contract($contract, __('You approved the contract.'));
    }

    public function reject(RejectContractRequest $request, Contract $contract): JsonResponse
    {
        $this->review->rejectAsParty($request->user(), $contract, $request->validated('note'));

        return $this->contract($contract, __('The contract was sent back to the lawyer with your note.'));
    }

    private function contract(Contract $contract, string $message): JsonResponse
    {
        $contract = $contract->fresh(['property', 'latestAiJob', 'currentVersion.clauses']);

        return response()->json(['message' => $message, 'data' => new ContractResource($contract)]);
    }
}
