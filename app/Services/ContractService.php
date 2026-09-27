<?php

namespace App\Services;

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Models\AiJob;
use App\Models\Contract;
use App\Models\PropertyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A contract's life up to the lawyer's review: born from an accepted
 * request, drafted by the AI, then — when the assigned lawyer submits it —
 * analysed by the AI. Every step that hands work to the AI goes through
 * AiJobService::queue() inside a transaction.
 */
class ContractService
{
    public function __construct(private readonly AiJobService $ai) {}

    /**
     * Called by PropertyRequestService::lawyerAccept(), inside its
     * transaction. The contract starts as `draft` and the AI drafts it.
     */
    public function createFromRequest(User $lawyer, PropertyRequest $request): Contract
    {
        $property = $request->property;
        $jurisdictionId = DB::table('jurisdictions')->value('id');
        abort_if(! $jurisdictionId, 500, 'No jurisdiction configured.');

        $contract = Contract::create([
            'tenant_id' => $request->tenant_id,
            'reference' => $this->generateReference($request->tenant_id),
            'request_id' => $request->id,
            'property_id' => $property->id,
            'owner_id' => $property->owner_id,
            'beneficiary_id' => $request->requester_id,
            'lawyer_id' => $lawyer->id,
            'type' => $request->type,
            'status' => 'draft',
            'jurisdiction_id' => $jurisdictionId,
            // An offer, when one was made, is the agreed price; otherwise the listing's.
            'value_amount' => $request->offered_amount ?? $property->price_amount,
            'value_currency' => $request->offered_currency ?? $property->price_currency,
            'starts_on' => $request->term_start,
            'ends_on' => $request->term_end,
            'created_by' => $lawyer->id,
        ]);

        $this->ai->queue($contract, 'generate_contract', $lawyer);

        return $contract;
    }

    /** UC-008: the assigned lawyer sends the current draft for AI analysis. */
    public function submitForAnalysis(User $lawyer, Contract $contract): AiJob
    {
        $this->assertAssignedLawyer($lawyer, $contract);

        if ($contract->status !== 'draft' || ! $contract->current_version_id) {
            abort(409, 'Only a drafted contract can be submitted for analysis.');
        }

        return DB::transaction(function () use ($lawyer, $contract) {
            $contract->update(['status' => 'under_ai_review']);

            return $this->ai->queue($contract, 'analyze_contract', $lawyer, $contract->currentVersion);
        });
    }

    /** A draft that failed or timed out can be asked for again. */
    public function retryGeneration(User $lawyer, Contract $contract): AiJob
    {
        $this->assertAssignedLawyer($lawyer, $contract);

        if ($contract->status !== 'draft' || $contract->current_version_id) {
            abort(409, 'This contract already has a draft.');
        }

        if ($contract->aiJobs()->whereIn('status', AiJob::IN_FLIGHT)->exists()) {
            abort(409, 'A draft is already being generated.');
        }

        return DB::transaction(fn () => $this->ai->queue($contract, 'generate_contract', $lawyer));
    }

    private function assertAssignedLawyer(User $user, Contract $contract): void
    {
        if ($contract->lawyer_id !== $user->id) {
            throw AuthorizationFailedException::forbidden();
        }
    }

    private function generateReference(string $tenantId): string
    {
        do {
            $reference = 'CT-'.strtoupper(Str::random(8));
        } while (Contract::where('tenant_id', $tenantId)->where('reference', $reference)->exists());

        return $reference;
    }
}
