<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-13.5. Kept separate from the profile because it carries its own
     * verification lifecycle and only applies to one role.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.lawyer_credentials (
                user_id            uuid primary key references app.users (id) on delete cascade,
                license_number     varchar(64)  not null,
                bar_association    varchar(191) not null,
                jurisdiction_id    uuid         not null references app.jurisdictions (id),
                issued_at          date         not null,
                expires_at         date,
                verified_at        timestamptz,
                verified_by        uuid references app.users (id),
                document_path      text         not null,
                created_at         timestamptz  not null default now(),
                updated_at         timestamptz  not null default now(),

                constraint lawyer_credentials_validity check (expires_at is null or expires_at > issued_at)
            );

            create unique index lawyer_credentials_license_key
                on app.lawyer_credentials (jurisdiction_id, license_number);

            create trigger lawyer_credentials_touch
                before update on app.lawyer_credentials
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.lawyer_credentials;');
    }
};
