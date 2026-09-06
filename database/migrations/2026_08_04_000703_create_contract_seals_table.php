<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UC-059.
     *
     * Produced once, when the contract becomes fully signed: the final
     * rendered PDF, its SHA-256, the AI provenance required by NFR-12.3, and
     * the ordered signature evidence frozen as it stood. This row is what a QR
     * verification resolves against and what an expert would be handed in a
     * dispute.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.contract_seals (
                id                  uuid primary key default app.uuid_generate_v7(),
                tenant_id           uuid not null references app.tenants (id) on delete restrict,
                contract_id         uuid not null,
                contract_version_id uuid not null,

                pdf_path            text not null,
                pdf_sha256          app.sha256_hex not null,
                pdf_size_bytes      bigint not null,

                -- Provenance snapshot (NFR-12.3), copied rather than joined so
                -- the seal remains self-contained if the job record is ever
                -- pruned.
                model_id            varchar(128),
                model_version       varchar(64),
                kb_version_tag      varchar(64),

                -- Ordered [{signer_id, role, signed_at, document_hash, ip}, ...]
                signature_evidence  jsonb not null,
                signature_count     smallint not null,

                sealed_at           timestamptz not null default now(),

                constraint seals_signature_count_positive check (signature_count > 0),
                constraint seals_pdf_size_positive check (pdf_size_bytes > 0),
                constraint seals_evidence_is_array check (jsonb_typeof(signature_evidence) = 'array'),

                unique (id, tenant_id),
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete restrict,
                foreign key (contract_version_id, tenant_id)
                    references app.contract_versions (id, tenant_id) on delete restrict
            );

            -- Exactly one seal per contract. Re-sealing would create two
            -- documents both claiming to be final.
            create unique index seals_one_per_contract on app.contract_seals (contract_id);
            create unique index seals_pdf_hash_key     on app.contract_seals (pdf_sha256);

            create trigger seals_immutable
                before update or delete on app.contract_seals
                for each row execute function app.reject_any_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.contract_seals;');
    }
};
