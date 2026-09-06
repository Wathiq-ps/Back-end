<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-2.1, FR-2.3, UC-040 (KYC).
     *
     * Files live in private object storage and are served only via short-lived
     * signed URLs — this table holds the storage key, never a public URL.
     *
     * app.verification_status is created here and reused by
     * app.ownership_documents (see the 000303_* migration).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.verification_status as enum ('pending', 'under_review', 'approved', 'rejected');
            create type app.identity_document_type as enum ('national_id', 'passport', 'residency_permit', 'commercial_register');

            create table app.identity_documents (
                id             uuid primary key default app.uuid_generate_v7(),
                tenant_id      uuid not null references app.tenants (id) on delete restrict,
                user_id        uuid not null references app.users (id)   on delete cascade,
                type           app.identity_document_type not null,
                document_number varchar(64) not null,
                issuing_country_id uuid references app.countries (id),
                front_path     text        not null,
                back_path      text,
                expires_on     date,
                status         app.verification_status not null default 'pending',
                reviewed_by    uuid        references app.users (id),
                reviewed_at    timestamptz,
                rejection_reason text,
                created_at     timestamptz not null default now(),
                updated_at     timestamptz not null default now(),

                constraint identity_documents_review_complete check (
                    status not in ('approved', 'rejected') or (reviewed_by is not null and reviewed_at is not null)
                ),
                constraint identity_documents_rejection_has_reason check (
                    status <> 'rejected' or rejection_reason is not null
                )
            );

            -- One approved identity document per user per tenant; re-submission
            -- is allowed only while nothing is approved.
            create unique index identity_documents_approved_key
                on app.identity_documents (tenant_id, user_id)
                where status = 'approved';

            create index identity_documents_queue_idx
                on app.identity_documents (tenant_id, status, created_at)
                where status in ('pending', 'under_review');

            create trigger identity_documents_touch
                before update on app.identity_documents
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.identity_documents;
            drop type if exists app.identity_document_type;
            drop type if exists app.verification_status;
        SQL);
    }
};
