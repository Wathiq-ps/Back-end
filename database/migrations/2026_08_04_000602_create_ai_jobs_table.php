<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The async AI boundary — the API never calls an LLM inline.
     *
     * NFR-1.1 allows 60s for generation. Holding a PHP-FPM worker for that
     * would saturate the pool long before NFR-1.4's 1,000 concurrent users.
     * Laravel enqueues, the AI service calls back over a signed webhook.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.ai_job_kind   as enum ('generate_contract', 'analyze_contract', 'answer_query', 'summarize');
            create type app.ai_job_status as enum ('queued', 'dispatched', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out');

            create table app.ai_jobs (
                id                uuid primary key default app.uuid_generate_v7(),
                tenant_id         uuid not null references app.tenants (id) on delete restrict,
                contract_id       uuid,
                kind              app.ai_job_kind   not null,
                status            app.ai_job_status not null default 'queued',
                requested_by      uuid references app.users (id) on delete restrict,

                -- Provenance, mandated by NFR-12.3. Nullable until the job
                -- runs, then required on success — see the constraint below.
                provider          varchar(64),
                model_id          varchar(128),
                model_version     varchar(64),
                prompt_version    varchar(32),
                kb_version_id     uuid references knowledge.kb_versions (id) on delete restrict,

                input_hash        app.sha256_hex,
                tokens_input      integer,
                tokens_output     integer,
                latency_ms        integer,
                attempts          smallint not null default 0,
                error_code        varchar(64),
                error_message     text,

                queued_at         timestamptz not null default now(),
                dispatched_at     timestamptz,
                completed_at      timestamptz,

                -- A succeeded generation with no recorded model or KB version
                -- cannot satisfy NFR-12.3, so the database refuses to store one.
                constraint ai_jobs_success_has_provenance check (
                    status <> 'succeeded' or (
                        provider is not null and model_id is not null
                        and model_version is not null and kb_version_id is not null
                    )
                ),
                constraint ai_jobs_failure_has_code check (
                    status <> 'failed' or error_code is not null
                ),

                unique (id, tenant_id),
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade
            );

            create index ai_jobs_contract_idx on app.ai_jobs (contract_id, queued_at desc);
            create index ai_jobs_pending_idx  on app.ai_jobs (status, queued_at)
                where status in ('queued', 'dispatched', 'running');

            alter table app.contract_versions
                add constraint contract_versions_ai_job_fk
                foreign key (ai_job_id) references app.ai_jobs (id) on delete set null;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.contract_versions drop constraint if exists contract_versions_ai_job_fk;
            drop table if exists app.ai_jobs;
            drop type if exists app.ai_job_status;
            drop type if exists app.ai_job_kind;
        SQL);
    }
};
