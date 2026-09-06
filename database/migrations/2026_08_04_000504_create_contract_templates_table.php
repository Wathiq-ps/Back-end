<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** FR-13.8. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.contract_templates (
                id              uuid primary key default app.uuid_generate_v7(),
                tenant_id       uuid not null references app.tenants (id) on delete restrict,
                jurisdiction_id uuid not null references app.jurisdictions (id) on delete restrict,
                type            app.contract_type not null,
                name_ar         varchar(191) not null,
                name_en         varchar(191) not null,
                body            text not null,
                version         integer not null default 1,
                is_active       boolean not null default true,
                created_by      uuid not null references app.users (id) on delete restrict,
                created_at      timestamptz not null default now(),
                updated_at      timestamptz not null default now(),

                unique (id, tenant_id)
            );

            create unique index contract_templates_active_key
                on app.contract_templates (tenant_id, jurisdiction_id, type)
                where is_active;

            create trigger contract_templates_touch
                before update on app.contract_templates
                for each row execute function app.touch_updated_at();

            alter table app.contracts
                add constraint contracts_template_fk
                foreign key (template_id, tenant_id)
                references app.contract_templates (id, tenant_id) on delete restrict;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.contracts drop constraint if exists contracts_template_fk;
            drop table if exists app.contract_templates;
        SQL);
    }
};
