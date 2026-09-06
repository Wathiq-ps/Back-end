<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** FR-3.4, UC-003. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.property_media (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                property_id  uuid not null,
                disk         varchar(32)  not null default 'private',
                path         text         not null,
                mime_type    varchar(96)  not null,
                size_bytes   bigint       not null,
                width        integer,
                height       integer,
                checksum     app.sha256_hex not null,
                is_cover     boolean      not null default false,
                sort_order   smallint     not null default 0,
                created_at   timestamptz  not null default now(),

                constraint property_media_size_positive check (size_bytes > 0),
                constraint property_media_is_image check (mime_type like 'image/%'),

                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete cascade
            );

            create index property_media_property_idx on app.property_media (property_id, sort_order);
            create unique index property_media_single_cover on app.property_media (property_id) where is_cover;
            -- Same file uploaded twice to one listing is a duplicate, not two photos.
            create unique index property_media_checksum_key on app.property_media (property_id, checksum);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.property_media;');
    }
};
