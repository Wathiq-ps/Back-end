<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 12 (FR-12.1 .. FR-12.5), NFR-12.3.
     *
     * The `knowledge` schema is a privilege boundary. The AI service holds a
     * role with rights there and nowhere else: it receives contract text in
     * the job payload over HTTP and therefore has no business reading
     * app.contracts.
     *
     * pgvector rather than a separate vector store. The decisive argument is
     * not convenience but atomicity: the chunk, its metadata, its law version
     * and its embedding are one row, so a re-index either lands or does not.
     * Split across two datastores it is a two-phase commit that can tear,
     * leaving generations attributed to a KB version that never fully existed
     * — unacceptable when the output is a legal instrument.
     *
     * OPEN ITEM — embedding dimension is pinned at 1536 while the LLM provider
     * is undecided. kb_versions records the model and dimension per version,
     * so a change is a new version and a full re-embed (the FR-12.3 re-index
     * flow). If the chosen model has a different dimension, change it here
     * before seeding.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.kb_version_status as enum ('building', 'ready', 'active', 'superseded', 'failed');

            create table knowledge.kb_versions (
                id                   uuid primary key default app.uuid_generate_v7(),
                tag                  varchar(64) not null,
                jurisdiction_id      uuid not null references app.jurisdictions (id) on delete restrict,
                embedding_model      varchar(128) not null,
                embedding_dimensions smallint     not null,
                chunk_count          integer      not null default 0,
                status               app.kb_version_status not null default 'building',
                built_at             timestamptz,
                activated_at         timestamptz,
                notes                text,
                created_at           timestamptz not null default now(),

                constraint kb_versions_dimensions_positive check (embedding_dimensions > 0)
            );

            create unique index kb_versions_tag_key on knowledge.kb_versions (jurisdiction_id, tag);
            -- Exactly one active version per jurisdiction. Retrieval reads the
            -- active one; a half-finished rebuild must never serve traffic.
            create unique index kb_versions_one_active
                on knowledge.kb_versions (jurisdiction_id)
                where status = 'active';

            create table knowledge.sources (
                id              uuid primary key default app.uuid_generate_v7(),
                jurisdiction_id uuid not null references app.jurisdictions (id) on delete restrict,
                law_type        app.law_type not null,
                title_ar        varchar(255) not null,
                title_en        varchar(255),
                publisher       varchar(191) not null,
                citation        varchar(191),
                source_url      text,
                effective_from  date not null,
                effective_to    date,
                is_verified     boolean not null default false,
                verified_by     uuid references app.users (id) on delete restrict,
                verified_at     timestamptz,
                created_at      timestamptz not null default now(),
                updated_at      timestamptz not null default now(),

                constraint sources_effective_range check (effective_to is null or effective_to > effective_from),
                constraint sources_verified_complete check (
                    is_verified = false or (verified_by is not null and verified_at is not null)
                )
            );

            create index sources_jurisdiction_idx on knowledge.sources (jurisdiction_id, law_type)
                where is_verified;

            create trigger sources_touch
                before update on knowledge.sources
                for each row execute function app.touch_updated_at();

            comment on column knowledge.sources.is_verified is
              'BR-23/BR-24: only verified sources may be retrieved for contract generation. Filter on this in every RAG query.';

            create table knowledge.documents (
                id           uuid primary key default app.uuid_generate_v7(),
                source_id    uuid not null references knowledge.sources (id) on delete cascade,
                title        varchar(255) not null,
                language     app.locale   not null default 'ar',
                article_ref  varchar(64),
                raw_path     text,
                checksum     app.sha256_hex not null,
                created_at   timestamptz not null default now()
            );

            create index documents_source_idx on knowledge.documents (source_id);

            create table knowledge.chunks (
                id              uuid primary key default app.uuid_generate_v7(),
                kb_version_id   uuid not null references knowledge.kb_versions (id) on delete cascade,
                document_id     uuid not null references knowledge.documents (id)   on delete cascade,
                jurisdiction_id uuid not null references app.jurisdictions (id)     on delete restrict,
                law_type        app.law_type not null,
                ordinal         integer      not null,
                content         text         not null,
                token_count     smallint,
                embedding       vector(1536) not null,
                effective_from  date         not null,
                effective_to    date,
                metadata        jsonb        not null default '{}'::jsonb,
                created_at      timestamptz  not null default now(),

                unique (kb_version_id, document_id, ordinal)
            );

            create index chunks_filter_idx
                on knowledge.chunks (kb_version_id, jurisdiction_id, law_type);
            create index chunks_effective_idx
                on knowledge.chunks (effective_from, effective_to);

            -- No HNSW index at launch, deliberately.
            --
            -- The Palestinian real-estate corpus is thousands to low tens of
            -- thousands of chunks. Exact search over that is a few milliseconds
            -- and returns exact results; an HNSW index would trade that accuracy
            -- for speed the workload does not need, and its recall degrades
            -- under the restrictive metadata filters this schema is built
            -- around. Enable it when measurement — not intuition — says the
            -- scan is the bottleneck, in its own migration:
            --
            --   create index concurrently chunks_embedding_hnsw
            --       on knowledge.chunks using hnsw (embedding vector_cosine_ops)
            --       with (m = 16, ef_construction = 64);
            --   -- per session: set hnsw.ef_search = 100;
            --   --              set hnsw.iterative_scan = 'relaxed_order';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists knowledge.chunks;
            drop table if exists knowledge.documents;
            drop table if exists knowledge.sources;
            drop table if exists knowledge.kb_versions;
            drop type if exists app.kb_version_status;
        SQL);
    }
};
