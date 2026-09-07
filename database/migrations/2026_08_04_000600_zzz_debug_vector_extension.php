<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('create extension if not exists vector');
            $attempt = 'CREATED_OK';
        } catch (\Throwable $e) {
            $attempt = 'FAILED: '.$e->getMessage();
        }

        $exts = DB::select('select extname, extnamespace::regnamespace as ns from pg_extension order by extname');
        $searchPath = DB::selectOne('show search_path')->search_path;
        $summary = collect($exts)->map(fn ($e) => "{$e->extname} in {$e->ns}")->implode(', ');
        $migrated = collect(DB::select('select migration from migrations order by id'))->pluck('migration')->implode(', ');

        throw new \RuntimeException("DEBUG2 vectorAttempt=[{$attempt}] extensions=[{$summary}] search_path={$searchPath} migrated=[{$migrated}]");
    }

    public function down(): void
    {
    }
};
