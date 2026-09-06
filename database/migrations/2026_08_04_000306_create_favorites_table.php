<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.favorites (
                user_id     uuid not null references app.users (id) on delete cascade,
                property_id uuid not null,
                tenant_id   uuid not null,
                created_at  timestamptz not null default now(),

                primary key (user_id, property_id),
                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete cascade
            );

            create index favorites_property_idx on app.favorites (property_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.favorites;');
    }
};
