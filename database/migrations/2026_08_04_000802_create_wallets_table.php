<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-7.8 is classified Phase 2, but UC-022 makes the wallet the primary
     * payment method in the MVP. A minimal wallet — balance, debit, ledger —
     * is therefore unavoidable now.
     *
     * The ledger is append-only and the balance is a cache over it, verified
     * on every write (see the 000803_* migration). A payments system where the
     * balance column can drift from the entries is a payments system that will
     * eventually be wrong and unable to prove when it started being wrong.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.wallets (
                id             uuid primary key default app.uuid_generate_v7(),
                tenant_id      uuid not null references app.tenants (id) on delete restrict,
                user_id        uuid not null references app.users (id) on delete restrict,
                currency       app.currency_code not null references app.currencies (code),
                -- Cached projection of the entries. Maintained by trigger and
                -- verified against balance_after on every insert; never written
                -- directly.
                balance        app.money_minor not null default 0,
                is_frozen      boolean not null default false,
                created_at     timestamptz not null default now(),
                updated_at     timestamptz not null default now(),

                unique (id, tenant_id)
            );

            -- One wallet per user per currency. Mixing currencies in one
            -- balance is how multi-currency systems lose money.
            create unique index wallets_user_currency_key on app.wallets (tenant_id, user_id, currency);

            create trigger wallets_touch
                before update on app.wallets
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.wallets;');
    }
};
