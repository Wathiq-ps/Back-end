<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No 'users' / 'password_reset_tokens' here: identity lives in
        // app.users (see 2026_08_04_000201_create_users_table.php) with its
        // own verification and password-reset flow (app.one_time_tokens).
        //
        // 'sessions' stays: it is Laravel's own cookie/HTTP session store
        // (used by the web-based admin panel), unrelated to
        // app.user_sessions, which tracks JWT refresh-token rotation for the
        // API. Two different concepts, both needed.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
