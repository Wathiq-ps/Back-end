<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ponytail: postgis (geo search) stays out — needs a custom Postgres
        // image with it too, deferred until that feature is actually built.
        // vector (pgvector) is installed on the custom image and enabled here
        // for the AI service's RAG embeddings.
        DB::unprepared(<<<'SQL'
            create extension if not exists pgcrypto;    -- gen_random_bytes, digest
            create extension if not exists citext;      -- case-insensitive email
            create extension if not exists pg_trgm;     -- fuzzy property search
            create extension if not exists btree_gist;  -- uuid `=` inside GiST exclusion constraints
            create extension if not exists vector;      -- legal knowledge base embeddings
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop extension if exists vector;
            drop extension if exists btree_gist;
            drop extension if exists pg_trgm;
            drop extension if exists citext;
            drop extension if exists pgcrypto;
        SQL);
    }
};
