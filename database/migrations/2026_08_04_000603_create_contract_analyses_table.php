<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** FR-6.7 .. FR-6.10. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.contract_analyses (
                id                  uuid primary key default app.uuid_generate_v7(),
                tenant_id           uuid not null references app.tenants (id) on delete restrict,
                contract_id         uuid not null,
                contract_version_id uuid not null,
                ai_job_id           uuid not null references app.ai_jobs (id) on delete restrict,

                -- 0-100 integer. Deliberately not a float: the bands below are
                -- exact and a legal risk score that renders as
                -- 60.00000000000001 is indefensible.
                risk_score          smallint not null,
                risk_band           varchar(16) generated always as (
                    case
                        when risk_score <= 20 then 'very_low'
                        when risk_score <= 40 then 'low'
                        when risk_score <= 60 then 'medium'
                        when risk_score <= 80 then 'high'
                        else 'critical'
                    end
                ) stored,
                summary_ar          text,
                summary_en          text,
                report_path         text,
                created_at          timestamptz not null default now(),

                constraint analyses_risk_range check (risk_score between 0 and 100),

                unique (id, tenant_id),
                unique (contract_version_id),   -- one analysis per version
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade,
                foreign key (contract_version_id, tenant_id)
                    references app.contract_versions (id, tenant_id) on delete cascade
            );

            create index analyses_contract_idx on app.contract_analyses (contract_id, created_at desc);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.contract_analyses;');
    }
};
