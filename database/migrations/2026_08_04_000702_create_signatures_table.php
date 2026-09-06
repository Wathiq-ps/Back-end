<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-7.2, UC-046.
     *
     * If any part of this schema is going to be challenged in a dispute, it is
     * this one. Two rules drive the design:
     *
     *   1. A signature is evidence, not a status flag. It records who signed,
     *      when (server clock, never the client's), from where, and —
     *      critically — the hash of the document as it existed at that moment.
     *      Without that last field you can prove somebody signed but not what
     *      they agreed to.
     *   2. Everything here is append-only. There is no legitimate reason to
     *      update a signature row; the ability to do so is itself the
     *      vulnerability.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.signatures (
                id                  uuid primary key default app.uuid_generate_v7(),
                tenant_id           uuid not null references app.tenants (id) on delete restrict,
                contract_id         uuid not null,
                contract_version_id uuid not null,
                invitation_id       uuid references app.signature_invitations (id) on delete restrict,
                signer_id           uuid not null references app.users (id) on delete restrict,
                signer_role         app.signer_role not null,

                -- Identity as it stood at signing time. Denormalised on
                -- purpose: if the user later changes their legal name, the
                -- contract must still show the name that signed it.
                signer_name_snapshot     varchar(191) not null,
                signer_national_id_snapshot varchar(32),

                method              app.signature_method not null,
                signature_image_path text,

                -- The evidentiary core. Must equal the content_hash of
                -- contract_version_id at the moment of signing; the trigger
                -- below verifies it rather than trusting the caller.
                document_hash       app.sha256_hex not null,

                -- Server clock. Never accept a client-supplied signing time.
                signed_at           timestamptz not null default now(),
                ip_address          inet not null,
                user_agent          text not null,
                -- Anything else worth preserving: geolocation, OTP reference,
                -- device id.
                evidence            jsonb not null default '{}'::jsonb,

                constraint signatures_drawn_has_image check (
                    method not in ('drawn', 'uploaded_image') or signature_image_path is not null
                ),

                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete restrict,
                foreign key (contract_version_id, tenant_id)
                    references app.contract_versions (id, tenant_id) on delete restrict
            );

            -- A party signs a given version exactly once.
            create unique index signatures_one_per_signer_per_version
                on app.signatures (contract_version_id, signer_id);
            create index signatures_contract_idx on app.signatures (contract_id, signed_at);

            create trigger signatures_immutable
                before update or delete on app.signatures
                for each row execute function app.reject_any_change();

            -- Verify the hash against the version rather than taking the
            -- application's word for it. A mismatch means the signing flow
            -- read a different document than the one it recorded — the exact
            -- failure this column exists to make impossible.
            create or replace function app.verify_signature_document_hash()
            returns trigger
            language plpgsql
            as $$
            declare
              expected app.sha256_hex;
            begin
                select content_hash into expected
                  from app.contract_versions
                 where id = new.contract_version_id;

                if expected is null then
                    raise exception 'contract version % not found', new.contract_version_id
                        using errcode = 'foreign_key_violation';
                end if;

                if new.document_hash <> expected then
                    raise exception
                        'signature document_hash does not match contract version % (expected %, got %)',
                        new.contract_version_id, expected, new.document_hash
                        using errcode = 'restrict_violation',
                              hint = 'The signing flow rendered a document that is not the stored version.';
                end if;

                return new;
            end;
            $$;

            create trigger signatures_verify_hash
                before insert on app.signatures
                for each row execute function app.verify_signature_document_hash();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.signatures;
            drop function if exists app.verify_signature_document_hash();
        SQL);
    }
};
