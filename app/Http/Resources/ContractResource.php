<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class ContractResource extends JsonResource
{
    private ?object $sentBack = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'status' => $this->status,
            // Why it was sent back, and by whom: the lawyer (UC-070), for the
            // parties to act on, or a party rejecting the approved contract,
            // for the lawyer to.
            'status_reason' => $this->when($this->status === 'requires_modification', fn () => $this->sentBack()?->reason),
            'status_reason_by' => $this->when($this->status === 'requires_modification', fn () => $this->roleOf($this->sentBack()?->actor_id)),
            'value' => $this->valueMajor(),
            'value_currency' => $this->value_currency,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'owner_id' => $this->owner_id,
            'beneficiary_id' => $this->beneficiary_id,
            'lawyer_id' => $this->lawyer_id,
            // The parties' approvals of the lawyer-approved contract; both set
            // means it is ready for signature.
            'owner_approved_at' => $this->owner_approved_at,
            'beneficiary_approved_at' => $this->beneficiary_approved_at,
            'property' => $this->whenLoaded('property', fn () => [
                'id' => $this->property?->id,
                'reference' => $this->property?->reference,
                'title' => $this->property?->title,
            ]),
            // What the AI is doing, or last did, for this contract.
            'ai_job' => $this->whenLoaded('latestAiJob', fn () => $this->latestAiJob ? [
                'id' => $this->latestAiJob->id,
                'kind' => $this->latestAiJob->kind,
                'status' => $this->latestAiJob->status,
                'error_code' => $this->latestAiJob->error_code,
                'queued_at' => $this->latestAiJob->queued_at,
                'completed_at' => $this->latestAiJob->completed_at,
            ] : null),
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? [
                'id' => $this->currentVersion->id,
                'version_no' => $this->currentVersion->version_no,
                'author_type' => $this->currentVersion->author_type,
                'change_note' => $this->currentVersion->change_note,
                'body' => $this->currentVersion->body,
                'clauses' => $this->currentVersion->clauses->map(fn ($clause) => [
                    'id' => $clause->id,
                    'ordinal' => $clause->ordinal,
                    'kind' => $clause->kind,
                    'body' => $clause->body,
                ]),
                'created_at' => $this->currentVersion->created_at,
            ] : null),
            // Lawyer only — see ContractController::show().
            // After the lawyer edits, this is the analysis of an earlier
            // version: compare contract_version_id with current_version.id.
            'analysis' => $this->whenLoaded('latestAnalysis', fn () => $this->latestAnalysis ? [
                'id' => $this->latestAnalysis->id,
                'contract_version_id' => $this->latestAnalysis->contract_version_id,
                'risk_band' => $this->latestAnalysis->risk_band,
                'risk_score' => $this->latestAnalysis->risk_score,
                'risk_rubric_version' => $this->latestAnalysis->risk_rubric_version,
                'confidence' => $this->latestAnalysis->confidence,
                'summary_ar' => $this->latestAnalysis->summary_ar,
                'summary_en' => $this->latestAnalysis->summary_en,
                // All 11 clauses checked, including the ones that passed.
                'coverage' => $this->latestAnalysis->coverage,
                // The problems. Missing clauses appear here too; show one list or the other, not both merged.
                'findings' => $this->latestAnalysis->findings->map(fn ($finding) => [
                    'id' => $finding->id,
                    'kind' => $finding->kind,
                    'clause_kind' => $finding->clause_kind,
                    'clause_id' => $finding->clause_id,
                    'severity' => $finding->severity,
                    'title_ar' => $finding->title_ar,
                    'title_en' => $finding->title_en,
                    'description' => $finding->description,
                    'suggested_text' => $finding->suggested_text,
                    'citations' => $finding->citations,
                    'confidence' => $finding->confidence,
                    'resolution' => $finding->resolution,
                    'resolution_note' => $finding->resolution_note,
                    'resolved_at' => $finding->resolved_at,
                ]),
            ] : null),
            'created_at' => $this->created_at,
        ];
    }

    /** The latest move to requires_modification: its reason, and who made it. */
    private function sentBack(): ?object
    {
        return $this->sentBack ??= DB::table('contract_status_history')
            ->where('contract_id', $this->id)->where('to_status', 'requires_modification')
            ->orderByDesc('occurred_at')->orderByDesc('id')->first(['reason', 'actor_id']);
    }
}
