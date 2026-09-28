<?php

namespace App\Ai;

use App\Models\AiJob;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * What the AI service's callback must look like before anything is stored —
 * the Back-end's one copy of Wathiq-ps/Ai's openapi.yaml (JobCallback and the
 * two result schemas). Every fact about the AI's wire format that the
 * Back-end relies on lives here, so a drift shows up as one named violation
 * instead of a failed insert somewhere in AiJobService.
 *
 * Only what the Back-end reads is checked; extra keys pass, so the AI can add
 * fields without a release here.
 */
final class WireContract
{
    /** openapi.yaml ClauseKind. A sale carries the first 11; a rent all 16. */
    public const CLAUSE_KINDS = [
        'parties', 'subject', 'price', 'payment_terms', 'deposit', 'duration', 'obligations',
        'utilities', 'maintenance', 'handover', 'inspection', 'warranties', 'termination',
        'dispute_resolution', 'governing_law', 'other',
    ];

    public const STATUSES = ['succeeded', 'failed', 'timed_out'];

    public const FINDING_KINDS = ['missing_clause', 'legal_conflict', 'ambiguity', 'suggestion', 'risk'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const COVERAGE_STATUSES = ['present', 'incomplete', 'absent'];

    /**
     * Every way this callback breaks the contract for this job, as readable
     * messages; empty when it can be stored.
     *
     * @return list<string>
     */
    public static function violations(AiJob $job, array $callback): array
    {
        $validator = Validator::make($callback, self::rules($job->kind, $callback['status'] ?? null));

        return $validator->fails() ? $validator->errors()->all() : [];
    }

    private static function rules(string $kind, ?string $status): array
    {
        $rules = [
            // The callback must be for the job it names, not merely a job.
            'kind' => ['required', Rule::in([$kind])],
            'status' => ['required', Rule::in(self::STATUSES)],
            'usage' => ['sometimes', 'array'],
            'usage.prompt_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.completion_tokens' => ['sometimes', 'integer', 'min:0'],
        ];

        if ($status !== 'succeeded') {
            return $rules + [
                'error_code' => ['nullable', 'string', 'max:64'],
                'error' => ['nullable', 'string'],
            ];
        }

        // ai_jobs_success_has_provenance refuses a succeeded row without these.
        $rules += [
            'provenance' => ['required', 'array'],
            'provenance.provider' => ['required', 'string'],
            'provenance.model_id' => ['required', 'string'],
            'provenance.prompt_version' => ['required', 'string'],
            'provenance.kb_version_id' => ['required', 'uuid'],
            'result' => ['required', 'array'],
        ];

        return $rules + match ($kind) {
            'generate_contract' => [
                'result.body' => ['required', 'string'],
                'result.clauses' => ['required', 'array', 'min:1'],
                'result.clauses.*.clause_kind' => ['required', Rule::in(self::CLAUSE_KINDS)],
                'result.clauses.*.content' => ['required', 'string'],
                'result.clauses.*.citations' => ['sometimes', 'array'],
            ],
            'analyze_contract' => [
                'result.risk_score' => ['required', 'integer', 'between:0,100'],
                'result.risk_rubric_version' => ['required', 'string'],
                'result.summary_ar' => ['required', 'string'],
                'result.summary_en' => ['nullable', 'string'],
                'result.confidence' => ['nullable', 'numeric', 'between:0,1'],
                'result.coverage' => ['required', 'array'],
                'result.coverage.*.clause_kind' => ['required', Rule::in(self::CLAUSE_KINDS)],
                'result.coverage.*.status' => ['required', Rule::in(self::COVERAGE_STATUSES)],
                'result.findings' => ['present', 'array'],
                'result.findings.*.kind' => ['required', Rule::in(self::FINDING_KINDS)],
                'result.findings.*.clause_kind' => ['required', Rule::in(self::CLAUSE_KINDS)],
                'result.findings.*.severity' => ['required', Rule::in(self::SEVERITIES)],
                'result.findings.*.title_ar' => ['required', 'string'],
                'result.findings.*.title_en' => ['nullable', 'string'],
                'result.findings.*.description' => ['required', 'string'],
                'result.findings.*.suggested_text' => ['nullable', 'string'],
                'result.findings.*.citations' => ['present', 'array'],
                'result.findings.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            ],
            default => [],
        };
    }
}
