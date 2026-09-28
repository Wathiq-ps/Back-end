<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Three small guards on the knowledge base the AI service writes and this
     * app reads.
     *
     * - knowledge.citation_view: the AI's citations are references now —
     *   {chunk_id, article_ref, law}, no copied excerpt — so a reader who opens
     *   one looks the article's text up here by chunk_id.
     * - documents_source_checksum_key: the AI's ingest skips a text it already
     *   holds by checking (source_id, checksum) first; the index makes that
     *   check race-safe instead of advisory.
     * - wathiq_ai loses select/update on app.ai_jobs: the AI reports results
     *   through the signed callback and never touches the table.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create view knowledge.citation_view as
            select
                c.id                     as chunk_id,
                c.metadata ->> 'article' as article_ref,
                c.content,
                s.title_ar               as law,
                s.citation               as law_citation,
                v.tag                    as kb_tag,
                v.status                 as kb_status
            from knowledge.chunks c
            join knowledge.documents d   on d.id = c.document_id
            join knowledge.sources s     on s.id = d.source_id
            join knowledge.kb_versions v on v.id = c.kb_version_id;

            grant select on knowledge.citation_view to wathiq_app;

            create unique index documents_source_checksum_key
                on knowledge.documents (source_id, checksum);

            revoke select, update on app.ai_jobs from wathiq_ai;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            grant select, update on app.ai_jobs to wathiq_ai;
            drop index if exists knowledge.documents_source_checksum_key;
            drop view if exists knowledge.citation_view;
        SQL);
    }
};
