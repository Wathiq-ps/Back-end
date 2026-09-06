<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Runs last on purpose — the timestamp is 99xxxx so new table migrations
     * always sort before it.
     *
     * This file is where NFR-4.6 stops being a promise. The application role
     * is granted INSERT and SELECT on the audit log and nothing else; no code
     * path, no ORM callback, and no compromised credential can rewrite
     * history, because the privilege to do so was never granted.
     *
     * IMPORTANT: `grant ... on all tables in schema` is a snapshot, not a
     * standing rule. The ALTER DEFAULT PRIVILEGES block at the end covers
     * tables created by FUTURE migrations — but only those created by
     * wathiq_migrator. If you migrate as a different role, re-run this
     * migration (or the equivalent grants) after adding tables.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Start from zero.
            revoke all on schema app, knowledge, ops, audit from public;
            revoke all on all tables in schema app, knowledge, ops, audit from public;

            ------------------------------------------------------------------
            -- wathiq_migrator — owns the schema, runs migrations. Not the app.
            ------------------------------------------------------------------
            grant usage, create on schema app, knowledge, ops, audit to wathiq_migrator;
            grant all on all tables    in schema app, knowledge, ops, audit to wathiq_migrator;
            grant all on all sequences in schema app, knowledge, ops, audit to wathiq_migrator;
            grant all on all functions in schema app, knowledge, ops, audit to wathiq_migrator;

            ------------------------------------------------------------------
            -- wathiq_app — the Laravel API.
            ------------------------------------------------------------------
            grant usage on schema app, knowledge, ops, audit to wathiq_app;

            grant select, insert, update, delete on all tables in schema app  to wathiq_app;
            grant select, insert, update, delete on all tables in schema ops  to wathiq_app;
            grant usage, select on all sequences in schema app, ops to wathiq_app;
            grant execute on all functions in schema app to wathiq_app;

            -- The knowledge base is administered through the app
            -- (FR-12.1 .. FR-12.5) but embeddings are written only by the AI
            -- service.
            grant select, insert, update, delete on knowledge.sources, knowledge.documents, knowledge.kb_versions to wathiq_app;
            grant select on knowledge.chunks to wathiq_app;

            -- ***** The NFR-4.6 boundary *****
            grant select, insert on audit.audit_logs to wathiq_app;
            revoke update, delete, truncate on audit.audit_logs from wathiq_app;
            grant usage, select on all sequences in schema audit to wathiq_app;

            -- Append-only tables elsewhere. The triggers already reject
            -- mutation; removing the privilege as well means the attempt fails
            -- before a trigger has to fire, and makes the intent legible in
            -- \dp output.
            revoke update, delete on
                app.contract_versions,
                app.contract_clauses,
                app.contract_status_history,
                app.signatures,
                app.contract_seals,
                app.wallet_entries,
                app.payment_events,
                app.contract_executions,
                app.receipts
            from wathiq_app;

            ------------------------------------------------------------------
            -- wathiq_ai — the FastAPI service. Knowledge base only.
            --
            -- It receives contract text in the job payload over HTTP, so it
            -- has no reason to read app.contracts — and now no ability to. If
            -- the AI service is ever compromised, the blast radius is the
            -- public legal corpus, not the contracts.
            ------------------------------------------------------------------
            grant usage on schema knowledge to wathiq_ai;
            grant select, insert, update, delete on all tables in schema knowledge to wathiq_ai;
            grant usage, select on all sequences in schema knowledge to wathiq_ai;

            -- Just enough of `app` to resolve jurisdictions and report job outcomes.
            grant usage on schema app to wathiq_ai;
            grant select on app.jurisdictions, app.countries to wathiq_ai;
            grant select, update on app.ai_jobs to wathiq_ai;
            grant execute on function app.uuid_generate_v7() to wathiq_ai;

            -- Explicitly denied, and worth stating rather than leaving implicit.
            revoke all on app.contracts, app.contract_versions, app.contract_clauses,
                          app.users, app.user_profiles, app.identity_documents,
                          app.payments, app.wallets, app.wallet_entries, app.signatures
            from wathiq_ai;

            ------------------------------------------------------------------
            -- wathiq_readonly — analytics, BI, on-call inspection.
            ------------------------------------------------------------------
            grant usage on schema app, knowledge, audit to wathiq_readonly;
            grant select on all tables in schema app, knowledge, audit to wathiq_readonly;

            -- KYC documents, deeds and session/OTP secrets are not analytics data.
            revoke select on app.identity_documents, app.ownership_documents, app.user_sessions,
                             app.one_time_tokens, app.otp_codes
            from wathiq_readonly;

            ------------------------------------------------------------------
            -- Defaults for objects created later
            --
            -- Without these, every future migration silently creates a table
            -- the application cannot read, and the failure appears at runtime
            -- rather than at deploy time.
            ------------------------------------------------------------------
            -- No FOR ROLE clause, deliberately. Default privileges only apply
            -- to objects created BY the named role, so naming a role other
            -- than the one actually running migrations silently does nothing.
            -- Omitting it targets the current role — correct whether you
            -- migrate as postgres (Supabase), wathiq_migrator, or anything
            -- else. It also avoids needing membership in another role, which
            -- a non-superuser does not have.
            --
            -- Consequence: if you ever change WHICH role runs migrations,
            -- re-run this migration as the new role.
            alter default privileges in schema app, ops
                grant select, insert, update, delete on tables to wathiq_app;
            alter default privileges in schema app, ops
                grant usage, select on sequences to wathiq_app;
            alter default privileges in schema app
                grant execute on functions to wathiq_app;

            alter default privileges in schema knowledge
                grant select, insert, update, delete on tables to wathiq_ai;

            alter default privileges in schema app, knowledge
                grant select on tables to wathiq_readonly;

            alter default privileges in schema audit
                grant select, insert on tables to wathiq_app;

            ------------------------------------------------------------------
            -- Verification
            --
            -- Run in CI after migrating. A green migration that quietly granted
            -- the app DELETE on the audit log is worse than a failed one.
            ------------------------------------------------------------------
            create or replace function app.assert_privilege_invariants()
            returns void
            language plpgsql
            as $$
            begin
                if has_table_privilege('wathiq_app', 'audit.audit_logs', 'UPDATE')
                   or has_table_privilege('wathiq_app', 'audit.audit_logs', 'DELETE') then
                    raise exception 'NFR-4.6 violated: wathiq_app holds UPDATE or DELETE on audit.audit_logs'
                        using errcode = 'restrict_violation';
                end if;

                if has_table_privilege('wathiq_ai', 'app.contracts', 'SELECT') then
                    raise exception 'wathiq_ai can read app.contracts; the knowledge-schema boundary is broken'
                        using errcode = 'restrict_violation';
                end if;

                if has_table_privilege('wathiq_readonly', 'app.identity_documents', 'SELECT') then
                    raise exception 'wathiq_readonly can read KYC documents'
                        using errcode = 'restrict_violation';
                end if;

                perform app.assert_no_float_columns();
            end;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop function if exists app.assert_privilege_invariants();

            -- Every ALTER DEFAULT PRIVILEGES above must be undone here, for
            -- tables AND sequences AND functions. A leftover default-privilege
            -- entry pins the role, and the roles migration's own down() then
            -- fails with "role ... cannot be dropped because some objects
            -- depend on it".
            alter default privileges in schema app, ops
                revoke select, insert, update, delete on tables from wathiq_app;
            alter default privileges in schema app, ops
                revoke usage, select on sequences from wathiq_app;
            alter default privileges in schema app
                revoke execute on functions from wathiq_app;
            alter default privileges in schema audit
                revoke select, insert on tables from wathiq_app;

            alter default privileges in schema knowledge
                revoke select, insert, update, delete on tables from wathiq_ai;

            alter default privileges in schema app, knowledge
                revoke select on tables from wathiq_readonly;

            -- wathiq_migrator is included here too. Leaving its schema-level
            -- grants behind blocks DROP ROLE in the roles migration's down().
            revoke all on all tables    in schema app, knowledge, ops, audit from wathiq_app, wathiq_ai, wathiq_readonly, wathiq_migrator;
            revoke all on all sequences in schema app, knowledge, ops, audit from wathiq_app, wathiq_ai, wathiq_readonly, wathiq_migrator;
            revoke all on all functions in schema app, knowledge, ops, audit from wathiq_app, wathiq_ai, wathiq_readonly, wathiq_migrator;
            revoke all on schema app, knowledge, ops, audit from wathiq_app, wathiq_ai, wathiq_readonly, wathiq_migrator;
        SQL);
    }
};
