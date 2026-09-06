<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-7.18, UC-060.
     *
     * The human-readable timeline of what physically happened after signing —
     * distinct from contract_status_history (state transitions) and from the
     * audit log (compliance record).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.execution_step as enum (
                'contract_activated',
                'payment_received',
                'handover_code_issued',
                'handover_confirmed',
                'keys_delivered',
                'documents_delivered',
                'contract_closed',
                'note'
            );

            create table app.contract_executions (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                contract_id  uuid not null,
                step         app.execution_step not null,
                actor_id     uuid references app.users (id) on delete restrict,
                notes        text,
                attachments  jsonb not null default '[]'::jsonb,
                occurred_at  timestamptz not null default now(),

                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade
            );

            create index contract_executions_idx on app.contract_executions (contract_id, occurred_at);

            create trigger contract_executions_immutable
                before update or delete on app.contract_executions
                for each row execute function app.reject_any_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.contract_executions;
            drop type if exists app.execution_step;
        SQL);
    }
};
