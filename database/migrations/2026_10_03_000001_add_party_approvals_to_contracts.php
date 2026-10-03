<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Once the lawyer approves, the owner and the beneficiary each review the
     * contract. Each approval is stamped on the contract. A party who rejects
     * it sends it back to the lawyer with a note: the same
     * requires_modification the lawyer's UC-070 uses, so the note lands in
     * contract_status_history as the reason, with the party as the actor.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.contracts
                add column owner_approved_at       timestamptz,
                add column beneficiary_approved_at timestamptz;

            insert into app.contract_status_transitions_allowed (from_status, to_status, note) values
                ('approved', 'requires_modification', 'a party rejects the approved contract with a note for the lawyer');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            delete from app.contract_status_transitions_allowed
             where from_status = 'approved' and to_status = 'requires_modification';

            alter table app.contracts
                drop column if exists owner_approved_at,
                drop column if exists beneficiary_approved_at;
        SQL);
    }
};
