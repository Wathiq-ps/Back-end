<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clause-level breakdown, so AI findings and lawyer decisions can point at
     * a specific clause rather than a character offset into a blob.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.clause_kind as enum (
                'parties', 'subject', 'price', 'payment_terms', 'duration', 'obligations',
                'warranties', 'termination', 'dispute_resolution', 'governing_law', 'other'
            );

            create table app.contract_clauses (
                id                  uuid primary key default app.uuid_generate_v7(),
                tenant_id           uuid not null references app.tenants (id) on delete restrict,
                contract_version_id uuid not null,
                ordinal             smallint not null,
                kind                app.clause_kind not null,
                heading             varchar(255),
                body                text not null,
                is_ai_generated     boolean not null default true,

                unique (contract_version_id, ordinal),
                foreign key (contract_version_id, tenant_id)
                    references app.contract_versions (id, tenant_id) on delete cascade
            );

            create index contract_clauses_version_idx on app.contract_clauses (contract_version_id, ordinal);

            create trigger contract_clauses_immutable
                before update or delete on app.contract_clauses
                for each row execute function app.reject_any_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.contract_clauses;
            drop type if exists app.clause_kind;
        SQL);
    }
};
