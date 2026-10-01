<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Unlike `signature_path`, the profile image is not evidence — it is
     * shown to other users next to listings and requests — so it lives on
     * the public disk and the column holds that disk's storage key.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.users add column profile_image_path text;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.users drop column if exists profile_image_path;
        SQL);
    }
};
