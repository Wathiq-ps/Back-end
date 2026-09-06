<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  /**
   * Least privilege is the point. wathiq_app deliberately cannot mutate the
   * audit log; that is what makes NFR-4.6 ("un-modifiable, un-deletable") a
   * property of the database rather than a promise made by application code.
   * See 2026_08_04_990000_grant_wathiq_privileges.php for the grants.
   *
   * Passwords are placeholders here on purpose — this file is committed to
   * version control. Rotate them for real before staging/production:
   *   ALTER ROLE wathiq_app      WITH PASSWORD '...';
   *   ALTER ROLE wathiq_ai       WITH PASSWORD '...';
   *   ALTER ROLE wathiq_readonly WITH PASSWORD '...';
   *   ALTER ROLE wathiq_migrator WITH PASSWORD '...';
   * outside of version control (a deploy secret, not a migration).
   */
  public function up(): void
  {
    DB::unprepared(<<<'SQL'
            do $$
            begin
              if not exists (select 1 from pg_roles where rolname = 'wathiq_migrator') then
                create role wathiq_migrator login password 'migrator';
              end if;
              if not exists (select 1 from pg_roles where rolname = 'wathiq_app') then
                create role wathiq_app login password 'app';
              end if;
              if not exists (select 1 from pg_roles where rolname = 'wathiq_ai') then
                create role wathiq_ai login password 'ai';
              end if;
              if not exists (select 1 from pg_roles where rolname = 'wathiq_readonly') then
                create role wathiq_readonly login password '123';
              end if;
            end
            $$;
        SQL);
  }

  public function down(): void
  {
    DB::unprepared(<<<'SQL'
            drop role if exists wathiq_readonly;
            drop role if exists wathiq_ai;
            drop role if exists wathiq_app;
            drop role if exists wathiq_migrator;
        SQL);
  }
};
