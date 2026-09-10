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
    /**
     * The drops are what make `migrate:fresh` work. Laravel's db:wipe only
     * drops *tables*, and only in the schemas listed in `search_path`
     * (public, app, extensions). Everything else this project creates —
     * app's domains/enums/functions, and every table in knowledge, ops and
     * audit — survives the wipe and collides on the next run ("type
     * money_minor already exists"). Dropping the schemas here clears all of
     * it in one statement, so no --drop-types flag is needed.
     *
     * up() only ever runs against an empty migrations table (first install
     * or straight after a wipe), so there is nothing here to preserve.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            drop schema if exists app cascade;
            drop schema if exists knowledge cascade;
            drop schema if exists ops cascade;
            drop schema if exists audit cascade;

            create schema app;
            create schema knowledge;
            create schema ops;
            create schema audit;
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
