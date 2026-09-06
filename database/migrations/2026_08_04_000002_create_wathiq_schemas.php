<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The split is a privilege boundary, not organisation for its own sake:
     *   app       business data; the application role owns read/write here
     *   knowledge legal KB + embeddings; the AI service role reads ONLY this
     *   ops       outbox, idempotency, webhook deliveries — infrastructure state
     *   audit     append-only. No role holds UPDATE or DELETE.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create schema if not exists app;
            create schema if not exists knowledge;
            create schema if not exists ops;
            create schema if not exists audit;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop schema if exists app cascade;
            drop schema if exists knowledge cascade;
            drop schema if exists ops cascade;
            drop schema if exists audit cascade;
        SQL);
    }
};
