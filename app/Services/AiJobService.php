<?php

namespace App\Services;

use App\Jobs\SendAiJob;
use App\Models\AiJob;
use App\Models\Contract;
use App\Models\ContractAnalysis;
use App\Models\ContractVersion;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The Back-end's half of the AI wiring — the wire contract is Wathiq-ps/Ai's
 * openapi.yaml, and its BACKEND_INTEGRATION.md explains the fields.
 *
 * queue() writes the ai_jobs row and dispatches SendAiJob on the `database`
 * queue. Called inside the caller's transaction, the job row and the queue
 * row commit or roll back together, so a contract can never be left
 * "generating" with nothing actually sent — the transactional-outbox
 * guarantee, using the queue Laravel already has instead of a custom relay.
 *
 * apply() is the other end: it turns a callback into versions, clauses,
 * analyses and findings, and moves the contract along its state machine.
 */
class AiJobService
{
    public function queue(Contract $contract, string $kind, User $requestedBy, ?ContractVersion $version = null): AiJob
    {
        $job = AiJob::create([
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'contract_version_id' => $version?->id,
            'kind' => $kind,
            'status' => 'queued',
            'requested_by' => $requestedBy->id,
        ]);

        SendAiJob::dispatch($job->id);

        return $job;
    }

    /** The body of POST /v1/jobs, built from the contract as it stands at send time. */
    public function wirePayload(AiJob $job): array
    {
        $contract = $job->contract;

        return [
            'job_id' => $job->id,
            'kind' => $job->kind,
            'jurisdiction_id' => $contract->jurisdiction_id,
            'payload' => match ($job->kind) {
                'generate_contract' => $this->draftPayload($contract),
                'analyze_contract' => [
                    'contract_version_id' => $job->contract_version_id,
                    'content' => $job->contractVersion->body,
                    'contract_type' => $contract->type,
                ],
            },
        ];
    }

    /**
     * A result arrived for a job that is still in flight (the callback
     * controller has already checked that, under a row lock).
     */
    public function apply(AiJob $job, array $callback): void
    {
        // What the job cost. Sent on failures too: a failed job still spent
        // its tokens. latency_ms stays ours (dispatch to callback), which
        // covers the queue and the network as well as the AI's own work.
        $tokens = array_filter([
            'tokens_input' => $callback['usage']['prompt_tokens'] ?? null,
            'tokens_output' => $callback['usage']['completion_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        if ($callback['status'] !== 'succeeded') {
            $this->fail(
                $job,
                $callback['error_code'] ?? 'unknown',
                (string) ($callback['error'] ?? ''),
                $callback['status'] === 'timed_out' ? 'timed_out' : 'failed',
                $tokens,
            );

            return;
        }

        $provenance = $callback['provenance'];
        $job->update([
            'status' => 'succeeded',
            'provider' => $provenance['provider'],
            'model_id' => $provenance['model_id'],
            'model_version' => $provenance['model_version'] ?? $provenance['model_id'],
            'prompt_version' => $provenance['prompt_version'],
            'kb_version_id' => $provenance['kb_version_id'],
            'result' => $callback['result'],
            ...$tokens,
            'completed_at' => now(),
            'latency_ms' => $job->dispatched_at ? (int) $job->dispatched_at->diffInMilliseconds(now()) : null,
        ]);

        match ($job->kind) {
            'generate_contract' => $this->storeDraft($job, $callback['result']),
            'analyze_contract' => $this->storeAnalysis($job, $callback['result']),
        };
    }

    /**
     * Ends a job that is still in flight; a no-op on one that already
     * finished, so a late timeout check or a failed retry can never overwrite
     * a result. An analysis that never came back returns the contract for
     * editing. A failed draft leaves it in `draft` with no version, which is
     * what ContractService::retryGeneration() looks for.
     */
    public function fail(AiJob $job, string $code, string $message, string $status = 'failed', array $tokens = []): void
    {
        $ended = AiJob::whereKey($job->id)->whereIn('status', AiJob::IN_FLIGHT)->update([
            ...$tokens,
            'status' => $status,
            'error_code' => Str::limit($code, 64, ''),
            'error_message' => $message,
            'completed_at' => now(),
        ]);

        if ($ended && $job->kind === 'analyze_contract') {
            Contract::whereKey($job->contract_id)->where('status', 'under_ai_review')->update(['status' => 'draft']);
        }
    }

    private function storeDraft(AiJob $job, array $result): void
    {
        $contract = $job->contract;
        $hash = hash('sha256', $result['body']);

        // The AI service caches identical requests, so a retried draft can
        // come back byte-identical — and contract_versions_hash_key allows one
        // row per body. Reuse that version instead of failing on it.
        $version = $contract->versions()->where('content_hash', $hash)->first();

        if (! $version) {
            $version = $contract->versions()->create([
                'tenant_id' => $contract->tenant_id,
                'version_no' => (int) $contract->versions()->max('version_no') + 1,
                'body' => $result['body'],
                'body_format' => 'plain',
                'content_hash' => $hash,
                'author_type' => 'ai',
                'ai_job_id' => $job->id,
            ]);

            foreach ($result['clauses'] as $i => $clause) {
                $version->clauses()->create([
                    'tenant_id' => $contract->tenant_id,
                    'ordinal' => $i + 1,
                    'kind' => $clause['clause_kind'],
                    'body' => $clause['content'],
                    'is_ai_generated' => true,
                ]);
            }
        }

        $contract->update(['current_version_id' => $version->id]);
    }

    private function storeAnalysis(AiJob $job, array $result): void
    {
        $version = $job->contractVersion;

        $analysis = ContractAnalysis::create([
            'tenant_id' => $job->tenant_id,
            'contract_id' => $job->contract_id,
            'contract_version_id' => $version->id,
            'ai_job_id' => $job->id,
            'risk_score' => $result['risk_score'],
            'summary_ar' => $result['summary_ar'] ?? null,
            'summary_en' => $result['summary_en'] ?? null,
            'coverage' => $result['coverage'],
            'confidence' => $result['confidence'] ?? null,
            'risk_rubric_version' => $result['risk_rubric_version'] ?? null,
        ]);

        // AI drafts carry each clause kind once. `other` means the contract
        // as a whole, so it points at no clause.
        $clauseIds = $version->clauses()->where('kind', '!=', 'other')->pluck('id', 'kind');

        foreach ($result['findings'] as $finding) {
            $analysis->findings()->create([
                'tenant_id' => $job->tenant_id,
                'clause_id' => $clauseIds[$finding['clause_kind']] ?? null,
                'clause_kind' => $finding['clause_kind'],
                'kind' => $finding['kind'],
                'severity' => $finding['severity'],
                'title_ar' => Str::limit($finding['title_ar'], 255, ''),
                'title_en' => Str::limit((string) ($finding['title_en'] ?? ''), 255, '') ?: null,
                'description' => $finding['description'],
                'suggested_text' => $finding['suggested_text'] ?? null,
                'citations' => $finding['citations'],
                'confidence' => $finding['confidence'] ?? null,
            ]);
        }

        Contract::whereKey($job->contract_id)->where('status', 'under_ai_review')->update(['status' => 'pending_lawyer_review']);
    }

    private function draftPayload(Contract $contract): array
    {
        $property = $contract->property;
        [$first, $second] = $contract->type === 'rent' ? ['landlord', 'tenant'] : ['seller', 'buyer'];
        $present = fn ($value) => $value !== null;

        return [
            'contract_type' => $contract->type,
            'language' => 'ar',
            'parties' => [
                $this->party($first, $contract->owner),
                $this->party($second, $contract->beneficiary),
            ],
            'property' => array_filter($property->only([
                'type', 'city', 'district', 'address_line', 'building_number',
                'area_sqm', 'rooms', 'bathrooms', 'floor_number', 'is_furnished',
            ]), $present),
            // Everything the draft would otherwise leave as a [blank].
            'terms' => array_filter([
                // "450", not "450.000": in an Arabic contract a dot often
                // groups thousands, so JOD's 3 zero decimals read as 450,000.
                'price' => str_contains($price = $contract->valueMajor(), '.') ? rtrim(rtrim($price, '0'), '.') : $price,
                'currency' => $contract->value_currency,
                'price_unit' => $property->price_unit,
                'starts_on' => $contract->starts_on?->toDateString(),
                'ends_on' => $contract->ends_on?->toDateString(),
            ], $present),
        ];
    }

    private function party(string $role, User $user): array
    {
        return array_filter([
            'role' => $role,
            'name' => $user->name,
            'nationality' => $user->nationality,
            'document_type' => $user->document_type,
            'document_number' => $user->document_number,
        ], fn ($value) => $value !== null);
    }
}
