<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.user_profiles (
                user_id          uuid primary key references app.users (id) on delete cascade,
                first_name       varchar(96)  not null,
                last_name        varchar(96)  not null,
                display_name     varchar(191) generated always as (first_name || ' ' || last_name) stored,
                national_id      varchar(32),
                date_of_birth    date,
                nationality_id   uuid references app.countries (id),
                address_line     varchar(255),
                location_id      uuid references app.locations (id),
                avatar_path      text,
                bio              text,
                created_at       timestamptz  not null default now(),
                updated_at       timestamptz  not null default now(),

                constraint user_profiles_dob_sane check (
                    date_of_birth is null or date_of_birth between '1900-01-01' and current_date
                )
            );

            create trigger user_profiles_touch
                before update on app.user_profiles
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.user_profiles;');
    }
};
