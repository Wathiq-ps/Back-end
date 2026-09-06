<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The state machine, held in a table.
     *
     * A status column that any service can assign is not a state machine.
     * Putting the transition set in a table and checking it in a BEFORE UPDATE
     * trigger means every path into the database — Eloquent, a queue worker,
     * an admin running raw SQL at 2am, a future service in another language —
     * obeys the same rules. The alternative is trusting that all of them
     * remembered to.
     *
     * To add a transition later, INSERT a row here in a new migration. Do not
     * edit the trigger.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.contract_status_transitions_allowed (
                from_status app.contract_status not null,
                to_status   app.contract_status not null,
                note        text,

                primary key (from_status, to_status),
                constraint transitions_not_self check (from_status <> to_status)
            );

            insert into app.contract_status_transitions_allowed (from_status, to_status, note) values
                ('draft',                 'under_ai_review',       'UC-008 submit for analysis'),
                ('draft',                 'cancelled',             'UC-091'),
                ('under_ai_review',       'pending_lawyer_review', 'UC-047 analysis complete'),
                ('under_ai_review',       'draft',                 'analysis failed; return for edit'),
                ('under_ai_review',       'cancelled',             'UC-091'),
                ('pending_lawyer_review', 'approved',              'UC-027'),
                ('pending_lawyer_review', 'requires_modification', 'UC-070'),
                ('pending_lawyer_review', 'cancelled',             'UC-091'),
                ('requires_modification', 'pending_lawyer_review', 'UC-070 resubmission'),
                ('requires_modification', 'cancelled',             'UC-091'),
                ('approved',              'awaiting_signatures',   'UC-030 send to sign'),
                ('approved',              'cancelled',             'UC-091'),
                ('awaiting_signatures',   'fully_signed',          'UC-046 / UC-058 all parties signed'),
                ('awaiting_signatures',   'cancelled',             'UC-091'),
                ('awaiting_signatures',   'expired',               'signature window elapsed'),
                ('fully_signed',          'awaiting_payment',      'UC-059 final version issued'),
                ('awaiting_payment',      'active',                'UC-022 / UC-051 payment verified'),
                ('awaiting_payment',      'cancelled',             'UC-091 — see the guard below'),
                ('active',                'completed',             'UC-054 / UC-055 handover confirmed'),
                ('active',                'cancelled',             'UC-091 — see the guard below'),
                ('active',                'expired',               'rental term elapsed');
            -- 'completed', 'cancelled' and 'expired' are terminal: no row has
            -- them as from_status.

            create or replace function app.enforce_contract_transition()
            returns trigger
            language plpgsql
            as $$
            begin
                if new.status = old.status then
                    return new;
                end if;

                if not exists (
                    select 1
                      from app.contract_status_transitions_allowed t
                     where t.from_status = old.status
                       and t.to_status   = new.status
                ) then
                    raise exception 'illegal contract transition % -> % on contract %',
                        old.status, new.status, old.id
                        using errcode = 'restrict_violation',
                              hint = 'Permitted transitions are rows in app.contract_status_transitions_allowed.';
                end if;

                -- UC-091 is the only transition that fires from many sources,
                -- and the two post-payment ones move money. FR-7.16 requires
                -- recording the effect of cancellation on payments, but refunds
                -- (FR-7.17) and transfers (FR-7.12) are Phase 2. Until a
                -- settlement path exists, cancelling a contract that has taken
                -- payment must be an explicit operations action, not something
                -- an ordinary request path can reach.
                if new.status = 'cancelled' and old.status in ('awaiting_payment', 'active') then
                    if coalesce(current_setting('wathiq.allow_settled_cancellation', true), 'off') <> 'on' then
                        raise exception
                            'contract % is in % and may have settled funds; cancellation requires an operations override',
                            old.id, old.status
                            using errcode = 'restrict_violation',
                                  hint = 'SET LOCAL wathiq.allow_settled_cancellation = ''on'' inside the reviewed ops transaction.';
                    end if;
                end if;

                -- Timestamp bookkeeping, so no service can forget it.
                if new.status = 'approved'  then new.approved_at  := coalesce(new.approved_at,  now()); end if;
                if new.status = 'active'    then new.activated_at := coalesce(new.activated_at, now()); end if;
                if new.status = 'completed' then new.completed_at := coalesce(new.completed_at, now()); end if;
                if new.status = 'cancelled' then new.cancelled_at := coalesce(new.cancelled_at, now()); end if;

                return new;
            end;
            $$;

            create trigger contracts_enforce_transition
                before update of status on app.contracts
                for each row execute function app.enforce_contract_transition();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists contracts_enforce_transition on app.contracts;
            drop function if exists app.enforce_contract_transition();
            drop table if exists app.contract_status_transitions_allowed;
        SQL);
    }
};
