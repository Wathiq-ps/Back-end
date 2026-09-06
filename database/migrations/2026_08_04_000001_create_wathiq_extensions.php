<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create extension if not exists pgcrypto;    -- gen_random_bytes, digest
            create extension if not exists citext;      -- case-insensitive email
            create extension if not exists pg_trgm;     -- fuzzy property search
            create extension if not exists btree_gist;  -- uuid `=` inside GiST exclusion constraints
            create extension if not exists postgis;     -- radius / nearest search (UC-017, UC-025)
            create extension if not exists vector;      -- legal knowledge base embeddings
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop extension if exists vector;
            drop extension if exists postgis;
            drop extension if exists btree_gist;
            drop extension if exists pg_trgm;
            drop extension if exists citext;
            drop extension if exists pgcrypto;
        SQL);
    }
};
