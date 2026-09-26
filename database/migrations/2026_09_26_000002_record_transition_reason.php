<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * contract_status_history.reason was only ever filled from
     * contracts.cancellation_reason, so a lawyer's "requires modification"
     * reason (UC-070) had nowhere to land. The trigger now also reads a
     * wathiq.transition_reason GUC, set per transaction the same way
     * wathiq.actor_id already is. A cancellation reason still wins.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function app.record_contract_transition()
            returns trigger
            language plpgsql
            as $$
            begin
                if new.status is distinct from old.status then
                    insert into app.contract_status_history
                        (tenant_id, contract_id, from_status, to_status, actor_id, reason)
                    values
                        (new.tenant_id, new.id, old.status, new.status,
                         nullif(current_setting('wathiq.actor_id', true), '')::uuid,
                         coalesce(new.cancellation_reason,
                                  nullif(current_setting('wathiq.transition_reason', true), '')));
                end if;
                return null;
            end;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function app.record_contract_transition()
            returns trigger
            language plpgsql
            as $$
            begin
                if new.status is distinct from old.status then
                    insert into app.contract_status_history
                        (tenant_id, contract_id, from_status, to_status, actor_id, reason)
                    values
                        (new.tenant_id, new.id, old.status, new.status,
                         nullif(current_setting('wathiq.actor_id', true), '')::uuid,
                         new.cancellation_reason);
                end if;
                return null;
            end;
            $$;
        SQL);
    }
};
