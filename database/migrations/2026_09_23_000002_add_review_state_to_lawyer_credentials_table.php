<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The table was built with only verified_at/verified_by, which can say
     * "approved" but not "waiting" or "turned down, here's why" — so an admin
     * review queue had nowhere to live. These columns mirror
     * app.identity_documents, whose review flow this one is a copy of.
     *
     * lawyer_credentials_verified_when_approved is the important one:
     * PropertyRequestService::accept() decides who may be assigned as a lawyer
     * by testing `verified_at is not null`, so that column and status must
     * never be able to disagree.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.lawyer_credentials
                add column status           app.verification_status not null default 'pending',
                add column rejection_reason text,
                add column reviewed_at      timestamptz,
                add column reviewed_by      uuid references app.users (id);

            -- Anything already carrying verified_at predates this column
            -- (LawyerSeeder's fixture) and is, by definition, approved.
            update app.lawyer_credentials
               set status = 'approved',
                   reviewed_at = verified_at
             where verified_at is not null;

            alter table app.lawyer_credentials
                add constraint lawyer_credentials_verified_when_approved check (
                    (verified_at is not null) = (status = 'approved')
                ),
                add constraint lawyer_credentials_review_complete check (
                    status not in ('approved', 'rejected') or reviewed_at is not null
                ),
                add constraint lawyer_credentials_rejection_has_reason check (
                    status <> 'rejected' or rejection_reason is not null
                );

            create index lawyer_credentials_queue_idx
                on app.lawyer_credentials (status, created_at)
                where status in ('pending', 'under_review');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index if exists app.lawyer_credentials_queue_idx;

            alter table app.lawyer_credentials
                drop constraint if exists lawyer_credentials_verified_when_approved,
                drop constraint if exists lawyer_credentials_review_complete,
                drop constraint if exists lawyer_credentials_rejection_has_reason,
                drop column if exists status,
                drop column if exists rejection_reason,
                drop column if exists reviewed_at,
                drop column if exists reviewed_by;
        SQL);
    }
};
