<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exts = DB::select('select extname, extnamespace::regnamespace as ns from pg_extension order by extname');
        $searchPath = DB::selectOne('show search_path')->search_path;
        $summary = collect($exts)->map(fn ($e) => "{$e->extname} in {$e->ns}")->implode(', ');

        throw new \RuntimeException("DEBUG extensions=[{$summary}] search_path={$searchPath}");
    }

    public function down(): void
    {
    }
};
