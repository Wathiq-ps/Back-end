<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NFR-1.2 full-text search.
     *
     * A trigger, chosen over a materialised view deliberately. A matview needs
     * REFRESH CONCURRENTLY, a unique index, a scheduler, and it is stale
     * between refreshes — an owner who fixes a typo expects the listing to be
     * findable immediately. A trigger keeps it transactional and always
     * current; at ~100k rows with a few hundred writes a day the write cost is
     * irrelevant next to the read benefit.
     *
     * Weights: A title + reference, B location, C description, D address.
     *
     * Reminder: queries must also pass through app.normalize_arabic(), or the
     * folding applied here will not match the folding NOT applied there.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function app.refresh_property_search()
            returns trigger
            language plpgsql
            as $$
            declare
              location_text text;
            begin
                select l.name_ar || ' ' || l.name_en
                  into location_text
                  from app.locations l
                 where l.id = new.location_id;

                new.search_document :=
                    setweight(app.to_bilingual_tsvector(coalesce(new.title, '')),        'A') ||
                    setweight(app.to_bilingual_tsvector(coalesce(location_text, '')),    'B') ||
                    setweight(app.to_bilingual_tsvector(coalesce(new.description, '')),  'C') ||
                    setweight(app.to_bilingual_tsvector(coalesce(new.address_line, '')), 'D') ||
                    setweight(to_tsvector('simple', coalesce(new.reference, '')),        'A');
                return new;
            end;
            $$;

            create trigger properties_search_document
                before insert or update of title, description, address_line, location_id, reference
                on app.properties
                for each row execute function app.refresh_property_search();

            create index properties_search_idx on app.properties using gin (search_document);

            -- Trigram fallback for short/misspelled fragments the tsvector
            -- will not match.
            create index properties_title_trgm_idx
                on app.properties using gin (app.normalize_arabic(title) gin_trgm_ops);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index if exists app.properties_title_trgm_idx;
            drop index if exists app.properties_search_idx;
            drop trigger if exists properties_search_document on app.properties;
            drop function if exists app.refresh_property_search();
        SQL);
    }
};
