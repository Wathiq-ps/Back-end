<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Curated by staff, not the owner — there is deliberately no field for
     * this on StorePropertyRequest/UpdatePropertyRequest. Powers the
     * home-page "featured" rail (separate from "top rated", which ranks by
     * average rating).
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
        });

        // Partial index: only 'published' rows are ever eligible for the
        // featured rail, and most properties will have is_featured = false.
        DB::statement(
            "create index properties_featured_idx on app.properties (published_at desc)
                where is_featured = true and status = 'published'"
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists app.properties_featured_idx');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
