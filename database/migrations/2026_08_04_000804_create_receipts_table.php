<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** FR-7.7. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.receipts (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                payment_id   uuid not null,
                number       varchar(32) not null,
                pdf_path     text,
                pdf_sha256   app.sha256_hex,
                issued_at    timestamptz not null default now(),

                unique (id, tenant_id),
                foreign key (payment_id, tenant_id)
                    references app.payments (id, tenant_id) on delete restrict
            );

            create unique index receipts_number_key  on app.receipts (tenant_id, number);
            create unique index receipts_payment_key on app.receipts (payment_id);

            create trigger receipts_immutable
                before update or delete on app.receipts
                for each row execute function app.reject_any_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.receipts;');
    }
};
