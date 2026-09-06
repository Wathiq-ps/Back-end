<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-7.1, UC-030.
     *
     * Bound to a specific contract_version_id. If the contract is amended
     * after invitations go out, the version changes and outstanding
     * invitations must be reissued — otherwise a party could sign a document
     * that has since been rewritten. The FK carries that rule.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.signer_role     as enum ('owner', 'beneficiary', 'lawyer', 'broker', 'witness');
            create type app.signature_method as enum ('drawn', 'typed', 'uploaded_image', 'otp_confirmed');
            create type app.signature_invitation_status as enum ('pending', 'viewed', 'signed', 'declined', 'expired', 'revoked');

            create table app.signature_invitations (
                id                  uuid primary key default app.uuid_generate_v7(),
                tenant_id           uuid not null references app.tenants (id) on delete restrict,
                contract_id         uuid not null,
                contract_version_id uuid not null,
                signer_id           uuid not null references app.users (id) on delete restrict,
                signer_role         app.signer_role not null,
                signing_order       smallint not null default 1,
                token_hash          app.sha256_hex not null,
                status              app.signature_invitation_status not null default 'pending',
                sent_at             timestamptz not null default now(),
                viewed_at           timestamptz,
                responded_at        timestamptz,
                expires_at          timestamptz not null,
                decline_reason      text,

                constraint invitations_expiry_after_send check (expires_at > sent_at),
                constraint invitations_declined_has_reason check (
                    status <> 'declined' or decline_reason is not null
                ),

                unique (id, tenant_id),
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade,
                foreign key (contract_version_id, tenant_id)
                    references app.contract_versions (id, tenant_id) on delete restrict
            );

            create unique index invitations_token_key on app.signature_invitations (token_hash);
            -- One live invitation per signer per version.
            create unique index invitations_one_live_per_signer
                on app.signature_invitations (contract_version_id, signer_id)
                where status in ('pending', 'viewed');
            create index invitations_contract_idx on app.signature_invitations (contract_id, signing_order);
            create index invitations_expiry_idx   on app.signature_invitations (expires_at)
                where status in ('pending', 'viewed');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.signature_invitations;
            drop type if exists app.signature_invitation_status;
            drop type if exists app.signature_method;
            drop type if exists app.signer_role;
        SQL);
    }
};
