<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The clause kinds are the AI service's vocabulary (Wathiq-ps/Ai
     * openapi.yaml, ClauseKind), not ours. As a closed enum, every kind the AI
     * adds needed a migration here first — and a rent draft carrying the five
     * new lease kinds (deposit, utilities, maintenance, handover, inspection)
     * failed its insert inside the callback, which surfaced as a 500, three
     * AI retries and a no_callback timeout. The columns become plain varchar;
     * App\Ai\WireContract is where the accepted kinds are checked now.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.contract_clauses
                alter column kind type varchar(64) using kind::text;

            alter table app.analysis_findings
                alter column clause_kind type varchar(64) using clause_kind::text;

            drop type app.clause_kind;
        SQL);
    }

    /**
     * Fails if any row carries a kind outside the original eleven — which is
     * the point: rolling back would lose them.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.clause_kind as enum (
                'parties', 'subject', 'price', 'payment_terms', 'duration', 'obligations',
                'warranties', 'termination', 'dispute_resolution', 'governing_law', 'other'
            );

            alter table app.contract_clauses
                alter column kind type app.clause_kind using kind::app.clause_kind;

            alter table app.analysis_findings
                alter column clause_kind type app.clause_kind using clause_kind::app.clause_kind;
        SQL);
    }
};
