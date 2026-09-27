<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Room for what the AI service sends back that the 0006xx tables had no
     * column for (see Wathiq-ps/Ai BACKEND_INTEGRATION.md).
     *
     * ai_jobs.contract_version_id: an analysis must record which version it
     * analysed, and the job is the only thing that knows by callback time.
     * ai_jobs.result: the raw result, lossless — a draft's citations live
     * only here, since they cover the whole document rather than a clause.
     * coverage: the 11-clause checklist, including the clauses that passed,
     * which findings alone cannot express.
     * analysis_findings.clause_kind: a missing clause has no clause row for
     * clause_id to point at, so without this the finding loses its clause.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.ai_jobs
                add column contract_version_id uuid references app.contract_versions (id) on delete set null,
                add column result              jsonb;

            alter table app.contract_analyses
                add column coverage            jsonb not null default '[]'::jsonb,
                add column confidence          numeric(4,3),
                add column risk_rubric_version varchar(16);

            alter table app.analysis_findings
                add column clause_kind         app.clause_kind,
                add column confidence          numeric(4,3);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.analysis_findings
                drop column if exists confidence,
                drop column if exists clause_kind;

            alter table app.contract_analyses
                drop column if exists risk_rubric_version,
                drop column if exists confidence,
                drop column if exists coverage;

            alter table app.ai_jobs
                drop column if exists result,
                drop column if exists contract_version_id;
        SQL);
    }
};
