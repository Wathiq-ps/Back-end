<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'pending' meant two different things: awaiting the *owner's* decision,
     * and — after the owner accepted and attached a lawyer — awaiting the
     * *lawyer's*. The owner's inbox couldn't tell those apart, so a request
     * already handed off still looked like it needed the owner. Splitting the
     * second one out as 'pending_lawyer_review' (same name app.contract_status
     * already uses for the equivalent stage) makes the queue honest.
     *
     * Postgres won't let a newly added enum value be *used* in the same
     * transaction that added it, hence $withinTransaction = false plus
     * separate statements: the ALTER TYPE has to commit before the index
     * predicate below can reference the new label.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared("alter type app.request_status add value if not exists 'pending_lawyer_review' after 'pending';");

        // requests_one_pending_per_requester stopped covering the whole "live
        // request" span the moment 'pending' got split: a request sitting with
        // the lawyer would otherwise leave the requester free to open a second
        // one on the same property.
        DB::unprepared(<<<'SQL'
            drop index if exists app.requests_one_pending_per_requester;

            create unique index requests_one_pending_per_requester
                on app.property_requests (property_id, requester_id)
                where status in ('pending', 'pending_lawyer_review');
        SQL);
    }

    public function down(): void
    {
        // Postgres cannot drop a single enum label, so the type is rebuilt
        // without it. Anything mid-review goes back to 'pending', which is
        // exactly what it meant before this migration.
        DB::unprepared(<<<'SQL'
            drop index if exists app.requests_one_pending_per_requester;
            drop index if exists app.requests_one_accepted_per_property;
            drop index if exists app.requests_expiry_idx;

            update app.property_requests
               set status = 'pending'
             where status = 'pending_lawyer_review';

            alter type app.request_status rename to request_status_old;

            create type app.request_status as enum (
                'pending', 'accepted', 'rejected', 'cancelled', 'closed', 'expired'
            );

            alter table app.property_requests
                alter column status drop default,
                alter column status type app.request_status using status::text::app.request_status,
                alter column status set default 'pending';

            drop type app.request_status_old;

            create unique index requests_one_pending_per_requester
                on app.property_requests (property_id, requester_id)
                where status = 'pending';

            create unique index requests_one_accepted_per_property
                on app.property_requests (property_id)
                where status = 'accepted';

            create index requests_expiry_idx
                on app.property_requests (expires_at)
                where status = 'pending';
        SQL);
    }
};
