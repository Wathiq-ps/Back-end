<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A lease drafted by the AI service carries five conditions the original
     * eleven lacked: the deposit, utilities, the maintenance split, the
     * handover and the landlord's access (Wathiq-ps/Ai openapi.yaml,
     * ClauseKind). Without them a rent draft fails its insert inside the
     * callback. The column stays an enum, so the database still refuses any
     * kind outside the list; App\Ai\WireContract::CLAUSE_KINDS must match it,
     * and AiWiringTest fails when the two drift.
     *
     * Placed in contract order. Postgres allows ADD VALUE in the migration's
     * transaction as long as nothing here uses the new values.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter type app.clause_kind add value if not exists 'deposit' after 'payment_terms';
            alter type app.clause_kind add value if not exists 'utilities' after 'obligations';
            alter type app.clause_kind add value if not exists 'maintenance' after 'utilities';
            alter type app.clause_kind add value if not exists 'handover' after 'maintenance';
            alter type app.clause_kind add value if not exists 'inspection' after 'handover';
        SQL);
    }

    /**
     * Postgres cannot drop an enum value, so the type is rebuilt with the
     * original eleven. Fails if any row carries one of the five — which is
     * the point: rolling back would lose them.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter type app.clause_kind rename to clause_kind_with_lease;

            create type app.clause_kind as enum (
                'parties', 'subject', 'price', 'payment_terms', 'duration', 'obligations',
                'warranties', 'termination', 'dispute_resolution', 'governing_law', 'other'
            );

            alter table app.contract_clauses
                alter column kind type app.clause_kind using kind::text::app.clause_kind;

            alter table app.analysis_findings
                alter column clause_kind type app.clause_kind using clause_kind::text::app.clause_kind;

            drop type app.clause_kind_with_lease;
        SQL);
    }
};
