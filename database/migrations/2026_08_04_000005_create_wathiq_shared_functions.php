<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Generic helpers used by many tables' own migrations:
     *   - uuid_generate_v7()      the default on every primary key
     *   - touch_updated_at()      updated_at maintenance trigger
     *   - reject_column_change()  guards specific immutable columns (by name)
     *   - reject_any_change()     guards a whole row (append-only tables)
     *   - assert_no_float_columns() CI check — no float/double in money columns
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- UUIDv7: time-ordered, so inserts stay at the right edge of the
            -- B-tree instead of scattering across it the way v4 does.
            -- Public-facing IDs must not be sequential integers — those leak
            -- transaction volume to competitors and make enumeration trivial
            -- on a system where the objects are contracts.
            --
            -- PostgreSQL 18 ships uuidv7() natively. On 18+, drop this and alias it.
            create or replace function app.uuid_generate_v7()
            returns uuid
            language plpgsql
            volatile
            parallel safe
            -- gen_random_bytes() comes from pgcrypto, and pgcrypto does not
            -- live in the same schema everywhere: a local `create extension`
            -- puts it in public, while Supabase pre-installs it in
            -- `extensions` (so our `if not exists` is a no-op and it stays
            -- there). Pinning the search_path on the function makes it
            -- resolve in both, and stops a caller's session search_path from
            -- changing what this function means.
            set search_path = public, extensions, pg_temp
            as $$
            declare
              v_ms    bigint := (extract(epoch from clock_timestamp()) * 1000)::bigint;
              v_bytes bytea  := gen_random_bytes(16);
            begin
              v_bytes := set_byte(v_bytes, 0, ((v_ms >> 40) & 255)::int);
              v_bytes := set_byte(v_bytes, 1, ((v_ms >> 32) & 255)::int);
              v_bytes := set_byte(v_bytes, 2, ((v_ms >> 24) & 255)::int);
              v_bytes := set_byte(v_bytes, 3, ((v_ms >> 16) & 255)::int);
              v_bytes := set_byte(v_bytes, 4, ((v_ms >>  8) & 255)::int);
              v_bytes := set_byte(v_bytes, 5, ( v_ms        & 255)::int);
              v_bytes := set_byte(v_bytes, 6, (get_byte(v_bytes, 6) & 15) | 112);
              v_bytes := set_byte(v_bytes, 8, (get_byte(v_bytes, 8) & 63) | 128);
              return encode(v_bytes, 'hex')::uuid;
            end;
            $$;

            comment on function app.uuid_generate_v7() is
              'RFC 9562 UUIDv7. Time-ordered for index locality. Replace with native uuidv7() on PostgreSQL 18+.';

            create or replace function app.touch_updated_at()
            returns trigger
            language plpgsql
            as $$
            begin
              new.updated_at := now();
              return new;
            end;
            $$;

            -- Applied to columns that must never change after insert
            -- (signature evidence, seal hashes, ledger entries). Cheaper and
            -- more reliable than remembering.
            create or replace function app.reject_column_change()
            returns trigger
            language plpgsql
            as $$
            declare
              col text;
            begin
              foreach col in array tg_argv loop
                if to_jsonb(new) -> col is distinct from to_jsonb(old) -> col then
                  raise exception
                    'column %.%.% is immutable once written', tg_table_schema, tg_table_name, col
                    using errcode = 'restrict_violation';
                end if;
              end loop;
              return new;
            end;
            $$;

            -- Total immutability: no column of a written row may change.
            -- Used by every append-only table (contract_versions, signatures,
            -- contract_seals, wallet_entries, ...).
            create or replace function app.reject_any_change()
            returns trigger
            language plpgsql
            as $$
            begin
                raise exception '%.% is append-only; create a new row instead of modifying %',
                    tg_table_schema, tg_table_name, old.id
                    using errcode = 'restrict_violation';
            end;
            $$;

            -- backendArch.md specifies a CI check for this rule. This is the
            -- same rule enforced one layer lower, where it cannot be skipped
            -- by someone running migrations by hand.
            create or replace function app.assert_no_float_columns()
            returns void
            language plpgsql
            as $$
            declare
              offending text;
            begin
              select string_agg(format('%I.%I.%I (%s)', table_schema, table_name, column_name, data_type), ', ')
                into offending
                from information_schema.columns
               where table_schema in ('app', 'ops', 'audit', 'knowledge')
                 and data_type in ('real', 'double precision')
                 and udt_name not in ('vector', 'geography', 'geometry');

              if offending is not null then
                raise exception 'floating-point columns are forbidden: %', offending
                  using errcode = 'restrict_violation',
                        hint = 'Monetary values use app.money_minor + app.currency_code. Scores use smallint or numeric.';
              end if;
            end;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop function if exists app.assert_no_float_columns();
            drop function if exists app.reject_any_change();
            drop function if exists app.reject_column_change();
            drop function if exists app.touch_updated_at();
            drop function if exists app.uuid_generate_v7();
        SQL);
    }
};
