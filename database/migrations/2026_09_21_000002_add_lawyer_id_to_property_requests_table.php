<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's "accept" step doesn't finalize the request by itself — it
     * hands it to a lawyer for review (see PropertyRequestService::accept()).
     * lawyer_id being set (while status stays 'pending') is what distinguishes
     * "awaiting the lawyer" from "awaiting the owner"; the lawyer's own
     * accept/reject decision is a later piece of work, not built yet.
     */
    public function up(): void
    {
        Schema::table('property_requests', function (Blueprint $table) {
            $table->foreignUuid('lawyer_id')->nullable()->after('requester_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('property_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lawyer_id');
        });
    }
};
