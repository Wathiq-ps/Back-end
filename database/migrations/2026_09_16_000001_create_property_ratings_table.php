<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Home-page "top rated" widget (5 highest-rated published properties)
     * reads off this table. A rating is earned, not volunteered: only the
     * beneficiary of a *completed* contract on the property may leave one,
     * and only once per contract — enforced in PropertyRatingService, not
     * here. A CHECK can't reach into app.contracts, and a cross-table
     * trigger would just duplicate the lookup the service already needs to
     * do to return a clean 403/409 instead of a constraint-violation 500.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.property_ratings (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                property_id  uuid not null,
                contract_id  uuid not null,
                rater_id     uuid not null references app.users (id) on delete restrict,
                score        smallint not null,
                comment      text,
                created_at   timestamptz not null default now(),
                updated_at   timestamptz not null default now(),

                constraint property_ratings_score_range check (score between 1 and 5),

                -- One rating per contract: a beneficiary who rents the same
                -- property twice earns a second rating slot, not a second
                -- vote on the first stay.
                unique (contract_id),

                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete cascade,
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete restrict
            );

            -- Serves the home-page query directly: avg(score) grouped by
            -- property, over published listings only.
            create index property_ratings_property_idx on app.property_ratings (property_id, score);

            create trigger property_ratings_touch
                before update on app.property_ratings
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.property_ratings;
        SQL);
    }
};
