<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-6.13.
     *
     * Append-only. A version is what a party actually signed; editing one
     * after the fact would destroy the evidentiary chain that
     * app.signatures.document_hash depends on. Lawyer edits create a new
     * version, never a mutation.
     *
     * ai_job_id's foreign key is added in the 000603_* migration, once
     * app.ai_jobs exists.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.contract_version_author as enum ('ai', 'lawyer', 'owner', 'admin', 'system');

            create table app.contract_versions (
                id            uuid primary key default app.uuid_generate_v7(),
                tenant_id     uuid not null references app.tenants (id) on delete restrict,
                contract_id   uuid not null,
                version_no    integer not null,
                body          text    not null,
                body_format   varchar(16) not null default 'markdown',
                -- SHA-256 over the canonical body. This is the value copied
                -- into every signature record, and the anchor of the whole
                -- integrity story.
                content_hash  app.sha256_hex not null,
                author_type   app.contract_version_author not null,
                author_id     uuid references app.users (id) on delete restrict,
                ai_job_id     uuid,   -- FK added in 000603_*
                change_note   text,
                created_at    timestamptz not null default now(),

                constraint contract_versions_no_positive check (version_no > 0),
                constraint contract_versions_human_has_author check (
                    author_type in ('ai', 'system') or author_id is not null
                ),

                unique (id, tenant_id),
                unique (contract_id, version_no),
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade
            );

            create index contract_versions_contract_idx on app.contract_versions (contract_id, version_no desc);
            create unique index contract_versions_hash_key on app.contract_versions (contract_id, content_hash);

            -- Total immutability: no column of a written version may change.
            create trigger contract_versions_immutable
                before update or delete on app.contract_versions
                for each row execute function app.reject_any_change();

            alter table app.contracts
                add constraint contracts_current_version_fk
                foreign key (current_version_id) references app.contract_versions (id) on delete restrict
                deferrable initially deferred;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.contracts drop constraint if exists contracts_current_version_fk;
            drop table if exists app.contract_versions;
            drop type if exists app.contract_version_author;
        SQL);
    }
};
