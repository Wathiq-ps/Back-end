<?php

use App\Support\Jurisdiction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * The knowledge base the AI writes and this app reads, and the jurisdiction
 * both key on. Raw Postgres (views, pgvector), so like AiWiringTest these run
 * only against Postgres.
 */
if (getenv('DB_CONNECTION') !== 'pgsql') {
    test('knowledge seam')->skip('Needs Postgres: set DB_CONNECTION=pgsql and DB_* to run these.');

    return;
}

uses(RefreshDatabase::class);

function palestine(): object
{
    return DB::table('jurisdictions')->where('code', 'PS')->first();
}

/** A verified source with one document and one chunk in a new kb_version. */
function seededChunk(): array
{
    $jurisdiction = palestine();
    $versionId = DB::table('knowledge.kb_versions')->insertGetId([
        'tag' => 'seam-test', 'jurisdiction_id' => $jurisdiction->id,
        'embedding_model' => 'test', 'embedding_dimensions' => 1536, 'status' => 'active',
    ]);
    $sourceId = DB::table('knowledge.sources')->insertGetId([
        'jurisdiction_id' => $jurisdiction->id, 'law_type' => 'rent',
        'title_ar' => 'قانون المالكين والمستأجرين رقم (62) لسنة 1953', 'publisher' => 'test',
        'effective_from' => '1953-01-01',
    ]);
    $documentId = DB::table('knowledge.documents')->insertGetId([
        'source_id' => $sourceId, 'title' => 'law 62', 'checksum' => str_repeat('a', 64),
    ]);
    $chunkId = DB::selectOne(<<<'SQL'
        insert into knowledge.chunks
            (kb_version_id, document_id, jurisdiction_id, law_type, ordinal, content, embedding, effective_from, metadata)
        values (?, ?, ?, 'rent', 1, ?, array_fill(0.0::real, array[1536])::vector, '1953-01-01', ?)
        returning id
        SQL, [$versionId, $documentId, $jurisdiction->id, 'لا يجوز إخراج المستأجر إلا ...', '{"article": "المادة (4)"}'])->id;

    return compact('sourceId', 'documentId', 'chunkId');
}

test('the jurisdiction is the one pinned by code, not whichever row comes first', function () {
    $ps = palestine();
    DB::table('jurisdictions')->insert([
        'country_id' => $ps->country_id, 'code' => 'GZ', 'name_ar' => 'غزة', 'name_en' => 'Gaza',
    ]);

    expect(Jurisdiction::defaultId())->toBe($ps->id);

    DB::table('jurisdictions')->where('id', $ps->id)->update(['is_active' => false]);
    expect(Jurisdiction::defaultId())->toBeNull();
});

test('a citation reference resolves to its article text through the view', function () {
    ['chunkId' => $chunkId] = seededChunk();

    $row = DB::table('knowledge.citation_view')->where('chunk_id', $chunkId)->first();

    expect($row->article_ref)->toBe('المادة (4)')
        ->and($row->content)->toStartWith('لا يجوز إخراج المستأجر')
        ->and($row->law)->toBe('قانون المالكين والمستأجرين رقم (62) لسنة 1953')
        ->and($row->kb_status)->toBe('active');
});

test('the same text cannot be ingested twice for one source', function () {
    ['sourceId' => $sourceId] = seededChunk();

    DB::table('knowledge.documents')->insert([
        'source_id' => $sourceId, 'title' => 'law 62 again', 'checksum' => str_repeat('a', 64),
    ]);
})->throws(QueryException::class, 'documents_source_checksum_key');

test('the AI role reads the knowledge base, not the job table', function () {
    $can = fn (string $privilege) => DB::selectOne(
        "select has_table_privilege('wathiq_ai', 'app.ai_jobs', ?) as ok", [$privilege]
    )->ok;

    expect($can('SELECT'))->toBeFalse()->and($can('UPDATE'))->toBeFalse();
});
