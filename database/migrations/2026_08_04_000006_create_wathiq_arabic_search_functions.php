<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Arabic search fails without this. Users type "احمد" and the listing
     * says "أحمد"; they type "شقه" and it says "شقة". The snowball stemmer
     * does not fold those. Apply normalize_arabic() to BOTH indexed text and
     * query text — asymmetric application silently breaks matching, and the
     * failure looks like "search is bad" rather than a bug.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function app.normalize_arabic(t text)
            returns text
            language sql
            immutable
            strict
            parallel safe
            as $$
              select translate(
                       regexp_replace(t, E'[ً-ْٰـ]', '', 'g'),
                       E'أإآٱةى٠١٢٣٤٥٦٧٨٩',
                       E'ااااهي0123456789'
                     );
            $$;

            comment on function app.normalize_arabic(text) is
              'Folds Arabic orthographic variants before tokenisation. Apply to both indexed text and query text — asymmetric application silently breaks matching.';

            -- Bilingual document builder. 'simple' retains exact tokens
            -- (reference numbers, transliterations); 'arabic' and 'english'
            -- add stemming.
            create or replace function app.to_bilingual_tsvector(t text)
            returns tsvector
            language sql
            immutable
            strict
            parallel safe
            as $$
              select to_tsvector('simple',  app.normalize_arabic(t))
                  || to_tsvector('arabic',  app.normalize_arabic(t))
                  || to_tsvector('english', t);
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop function if exists app.to_bilingual_tsvector(text);
            drop function if exists app.normalize_arabic(text);
        SQL);
    }
};
