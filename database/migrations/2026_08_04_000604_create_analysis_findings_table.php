<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** FR-6.7 .. FR-6.9, UC-028/UC-029 (lawyer accepts or rejects each finding). */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.finding_kind as enum ('missing_clause', 'legal_conflict', 'ambiguity', 'suggestion', 'risk');
            create type app.finding_severity as enum ('info', 'low', 'medium', 'high', 'critical');
            create type app.finding_resolution as enum ('open', 'accepted', 'rejected', 'superseded');

            create table app.analysis_findings (
                id            uuid primary key default app.uuid_generate_v7(),
                tenant_id     uuid not null references app.tenants (id) on delete restrict,
                analysis_id   uuid not null,
                clause_id     uuid references app.contract_clauses (id) on delete set null,
                kind          app.finding_kind     not null,
                severity      app.finding_severity not null,
                title_ar      varchar(255) not null,
                title_en      varchar(255),
                description   text not null,
                suggested_text text,
                -- Grounding: which knowledge chunks supported this finding.
                -- BR-25 forbids unsourced legal claims, so a finding with no
                -- citation is a bug and this column is how it becomes visible.
                citations     jsonb not null default '[]'::jsonb,
                resolution    app.finding_resolution not null default 'open',
                resolved_by   uuid references app.users (id) on delete restrict,
                resolved_at   timestamptz,
                resolution_note text,
                created_at    timestamptz not null default now(),

                constraint findings_resolution_complete check (
                    resolution = 'open' or (resolved_by is not null and resolved_at is not null)
                ),

                foreign key (analysis_id, tenant_id)
                    references app.contract_analyses (id, tenant_id) on delete cascade
            );

            create index findings_analysis_idx on app.analysis_findings (analysis_id, severity);
            create index findings_open_idx     on app.analysis_findings (analysis_id) where resolution = 'open';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.analysis_findings;
            drop type if exists app.finding_resolution;
            drop type if exists app.finding_severity;
            drop type if exists app.finding_kind;
        SQL);
    }
};
