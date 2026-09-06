<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-2.2, FR-2.4, UC-004.
     *
     * BR: a property may not be published until at least one ownership
     * document is approved. Enforced in the publish transition, not here — a
     * CHECK cannot span tables, and a trigger doing the lookup on every
     * property UPDATE would cost more than it protects.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.ownership_document_type as enum (
                'title_deed', 'sale_contract', 'inheritance_deed', 'power_of_attorney', 'municipal_record'
            );

            create table app.ownership_documents (
                id               uuid primary key default app.uuid_generate_v7(),
                tenant_id        uuid not null references app.tenants (id) on delete restrict,
                property_id      uuid not null,
                uploaded_by      uuid not null references app.users (id) on delete restrict,
                type             app.ownership_document_type not null,
                document_number  varchar(64),
                issued_on        date,
                path             text        not null,
                checksum         app.sha256_hex not null,
                status           app.verification_status not null default 'pending',
                reviewed_by      uuid        references app.users (id),
                reviewed_at      timestamptz,
                rejection_reason text,
                created_at       timestamptz not null default now(),
                updated_at       timestamptz not null default now(),

                constraint ownership_documents_review_complete check (
                    status not in ('approved', 'rejected') or (reviewed_by is not null and reviewed_at is not null)
                ),
                constraint ownership_documents_rejection_has_reason check (
                    status <> 'rejected' or rejection_reason is not null
                ),

                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete cascade
            );

            create index ownership_documents_property_idx on app.ownership_documents (property_id);
            create index ownership_documents_queue_idx
                on app.ownership_documents (tenant_id, status, created_at)
                where status in ('pending', 'under_review');

            create trigger ownership_documents_touch
                before update on app.ownership_documents
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.ownership_documents;
            drop type if exists app.ownership_document_type;
        SQL);
    }
};
