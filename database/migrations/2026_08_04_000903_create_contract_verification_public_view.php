<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UC-053 — public QR verification.
     *
     * Anyone can scan a QR code, so the verification endpoint is
     * unauthenticated. It must therefore be incapable of leaking contract
     * terms — not merely careful not to. This view is the entire public
     * surface: reference, status, masked party names, seal hash, dates. No
     * value, no clauses, no addresses.
     *
     * The endpoint reads this view and nothing else. Adding a column here
     * widens what any passer-by can read — treat changes as a security review,
     * and rate-limit the endpoint aggressively.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function app.mask_name(full_name text)
            returns text
            language sql
            immutable
            strict
            parallel safe
            as $$
                -- "Ahmad Yousef Khalil" -> "Ahmad Y. K."
                select (string_to_array(full_name, ' '))[1] || ' ' ||
                       coalesce(
                         string_agg(left(part, 1) || '.', ' ')
                           filter (where ordinality > 1),
                         ''
                       )
                  from unnest(string_to_array(full_name, ' ')) with ordinality as t(part, ordinality);
            $$;

            create or replace view app.contract_verification_public
            with (security_barrier = true)
            as
            select
                c.reference                              as contract_reference,
                c.status::text                           as status,
                c.type::text                             as contract_type,
                app.mask_name(op.display_name)           as owner_name_masked,
                app.mask_name(bp.display_name)           as beneficiary_name_masked,
                s.pdf_sha256                             as seal_hash,
                s.sealed_at                              as issued_at,
                j.name_ar                                as jurisdiction_ar,
                j.name_en                                as jurisdiction_en
              from app.contracts c
              join app.contract_seals s  on s.contract_id = c.id
              join app.user_profiles op  on op.user_id = c.owner_id
              join app.user_profiles bp  on bp.user_id = c.beneficiary_id
              join app.jurisdictions j   on j.id = c.jurisdiction_id
             where c.status in ('active', 'completed', 'expired', 'cancelled');

            comment on view app.contract_verification_public is
              'The complete unauthenticated surface for QR verification (UC-053). Adding a column here widens what any passer-by can read.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop view if exists app.contract_verification_public;
            drop function if exists app.mask_name(text);
        SQL);
    }
};
