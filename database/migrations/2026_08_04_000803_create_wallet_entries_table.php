<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** FR-7.9. The append-only ledger, plus the trigger that keeps the cached balance honest. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.ledger_direction as enum ('credit', 'debit');
            create type app.ledger_reference_type as enum ('payment', 'refund', 'topup', 'withdrawal', 'commission', 'adjustment');

            create table app.wallet_entries (
                id             uuid primary key default app.uuid_generate_v7(),
                tenant_id      uuid not null references app.tenants (id) on delete restrict,
                wallet_id      uuid not null,
                direction      app.ledger_direction not null,
                amount         app.money_minor not null,
                -- Running balance after this entry. Storing it makes the ledger
                -- auditable by inspection and lets the consistency trigger
                -- catch a lost update immediately rather than at month-end
                -- reconciliation.
                balance_after  app.money_signed not null,
                reference_type app.ledger_reference_type not null,
                reference_id   uuid,
                description    text,
                created_at     timestamptz not null default now(),

                constraint wallet_entries_amount_positive check (amount > 0),
                constraint wallet_entries_balance_non_negative check (balance_after >= 0),

                foreign key (wallet_id, tenant_id)
                    references app.wallets (id, tenant_id) on delete restrict
            );

            create index wallet_entries_wallet_idx on app.wallet_entries (wallet_id, created_at desc);
            create index wallet_entries_ref_idx    on app.wallet_entries (reference_type, reference_id);
            -- One ledger entry per source event: a replayed gateway callback
            -- must not credit the wallet twice.
            create unique index wallet_entries_reference_key
                on app.wallet_entries (wallet_id, reference_type, reference_id)
                where reference_id is not null;

            create trigger wallet_entries_immutable
                before update or delete on app.wallet_entries
                for each row execute function app.reject_any_change();

            -- Ledger integrity, enforced at write time.
            --
            -- Locks the wallet row, recomputes the balance from the entry, and
            -- rejects the insert if the caller's balance_after disagrees. The
            -- FOR UPDATE lock is what makes two concurrent debits serialise
            -- instead of both reading a stale balance — the classic
            -- double-spend, and the reason this belongs in the database rather
            -- than in a service that "remembers to use a transaction".
            create or replace function app.apply_wallet_entry()
            returns trigger
            language plpgsql
            as $$
            declare
              current_balance bigint;
              wallet_currency app.currency_code;
              is_frozen       boolean;
              new_balance     bigint;
            begin
                select balance, currency, w.is_frozen
                  into current_balance, wallet_currency, is_frozen
                  from app.wallets w
                 where w.id = new.wallet_id
                   for update;

                if not found then
                    raise exception 'wallet % not found', new.wallet_id
                        using errcode = 'foreign_key_violation';
                end if;

                if is_frozen then
                    raise exception 'wallet % is frozen', new.wallet_id
                        using errcode = 'restrict_violation';
                end if;

                new_balance := case new.direction
                                 when 'credit' then current_balance + new.amount
                                 when 'debit'  then current_balance - new.amount
                               end;

                if new_balance < 0 then
                    raise exception 'insufficient funds in wallet %: balance %, debit %',
                        new.wallet_id, current_balance, new.amount
                        using errcode = 'restrict_violation';
                end if;

                if new.balance_after <> new_balance then
                    raise exception
                        'wallet_entries.balance_after is inconsistent: computed %, supplied %',
                        new_balance, new.balance_after
                        using errcode = 'restrict_violation';
                end if;

                update app.wallets set balance = new_balance where id = new.wallet_id;
                return new;
            end;
            $$;

            create trigger wallet_entries_apply
                before insert on app.wallet_entries
                for each row execute function app.apply_wallet_entry();

            -- Standalone reconciliation check, for the nightly job and for tests.
            create or replace function app.assert_wallet_balances_consistent()
            returns void
            language plpgsql
            as $$
            declare
              drift record;
            begin
                for drift in
                    select w.id,
                           w.balance as cached,
                           coalesce(sum(case when e.direction = 'credit' then e.amount else -e.amount end), 0) as computed
                      from app.wallets w
                      left join app.wallet_entries e on e.wallet_id = w.id
                     group by w.id, w.balance
                    having w.balance <> coalesce(sum(case when e.direction = 'credit' then e.amount else -e.amount end), 0)
                loop
                    raise exception 'wallet % balance drift: cached %, ledger %',
                        drift.id, drift.cached, drift.computed
                        using errcode = 'data_exception';
                end loop;
            end;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop function if exists app.assert_wallet_balances_consistent();
            drop table if exists app.wallet_entries;
            drop function if exists app.apply_wallet_entry();
            drop type if exists app.ledger_reference_type;
            drop type if exists app.ledger_direction;
        SQL);
    }
};
