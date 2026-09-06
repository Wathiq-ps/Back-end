<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SRS 2.1 and 2.8 both mandate isolation per real-estate company, but no
     * use case across all 134 creates or manages a tenant. tenant_id
     * therefore lands on every business table from migration #1 while the
     * MVP seeds exactly one row. Retrofitting this across ~40 tables holding
     * live contract data is a six-week job with a data-integrity risk on
     * legal documents; carrying the column now costs a few hours.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.tenant_status as enum ('active', 'suspended', 'closed');

            create table app.tenants (
                id           uuid primary key default app.uuid_generate_v7(),
                slug         varchar(63)      not null,
                name_ar      varchar(255)     not null,
                name_en      varchar(255)     not null,
                status       app.tenant_status not null default 'active',
                settings     jsonb            not null default '{}'::jsonb,
                created_at   timestamptz      not null default now(),
                updated_at   timestamptz      not null default now(),

                constraint tenants_slug_format check (slug ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$')
            );

            create unique index tenants_slug_key on app.tenants (slug);

            create trigger tenants_touch
                before update on app.tenants
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.tenants;
            drop type if exists app.tenant_status;
        SQL);
    }
};
