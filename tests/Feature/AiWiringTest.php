<?php

use App\Jobs\CheckAiJobTimeout;
use App\Jobs\SendAiJob;
use App\Models\AiJob;
use App\Models\Contract;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiJobService;
use App\Services\JwtService;
use Database\Seeders\LawyerSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * The AI wiring end to end, minus the AI itself: lawyer accepts a request →
 * contract + draft job → SendAiJob → signed callback → versions, analyses,
 * status moves. These need the real schema (raw Postgres SQL, enums,
 * triggers), which SQLite can't load — CI runs them against a Postgres
 * service; locally, point DB_CONNECTION/DB_* at a Postgres with pgvector.
 */
if (getenv('DB_CONNECTION') !== 'pgsql') {
    test('AI wiring')->skip('Needs Postgres: set DB_CONNECTION=pgsql and DB_* to run these.');

    return;
}

uses(RefreshDatabase::class);

const CLAUSE_KINDS = [
    'parties', 'subject', 'price', 'payment_terms', 'duration', 'obligations',
    'warranties', 'termination', 'dispute_resolution', 'governing_law', 'other',
];

beforeEach(function () {
    config([
        'jwt.secret' => str_repeat('s', 64),
        'services.ai.url' => 'http://ai.test',
        'services.ai.webhook_secret' => 'test-secret',
    ]);
    Queue::fake();

    $this->seed([TenantSeeder::class, LawyerSeeder::class]);
    $this->lawyer = User::whereHas('lawyerCredential', fn ($q) => $q->where('status', 'approved'))->firstOrFail();
    $this->owner = verifiedUser('Owner Name');
    $requester = verifiedUser('Tenant Name');

    $this->property = Property::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'type' => 'apartment',
        'listing_type' => 'rent',
        'price_amount' => 450000, // 450.000 JOD — JOD has 3 decimals
        'price_currency' => 'JOD',
        'price_unit' => 'per_month',
    ]);

    $this->request = PropertyRequest::create([
        'tenant_id' => $this->property->tenant_id,
        'reference' => 'RQ-'.strtoupper(Str::random(8)),
        'property_id' => $this->property->id,
        'requester_id' => $requester->id,
        'lawyer_id' => $this->lawyer->id,
        'type' => 'rent',
        'term_start' => '2026-10-01',
        'term_end' => '2027-09-30',
        'status' => 'pending_lawyer_review',
    ]);

    $this->kbVersionId = DB::table('knowledge.kb_versions')->insertGetId([
        'tag' => 'test',
        'jurisdiction_id' => DB::table('jurisdictions')->value('id'),
        'embedding_model' => 'test',
        'embedding_dimensions' => 1536,
    ]);
});

function verifiedUser(string $name): User
{
    $user = User::factory()->create([
        'name' => $name,
        'nationality' => 'Palestinian',
        'document_type' => 'national_id',
        'document_number' => (string) random_int(100000000, 999999999),
    ]);

    DB::table('identity_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => Tenant::where('slug', 'default')->value('id'),
        'user_id' => $user->id,
        'type' => 'national_id',
        'document_number' => $user->document_number,
        'front_path' => 'kyc/front.jpg',
        'selfie_path' => 'kyc/selfie.jpg',
        'status' => 'approved',
        'reviewed_by' => $user->id,
        'reviewed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function actingAsUser(User $user): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issueAccessToken($user)];
}

/** What the AI service would POST back, signed the way it signs it. */
function postCallback(array $body, ?int $timestamp = null)
{
    $raw = json_encode($body);
    $t = $timestamp ?? time();
    $signature = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, 'test-secret');

    return test()->call('POST', '/api/v1/ai/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WATHIQ_SIGNATURE' => $signature,
    ], $raw);
}

function callback(AiJob $job, string $status, ?array $result = null, ?string $errorCode = null): array
{
    return [
        'job_id' => $job->id,
        'kind' => $job->kind,
        'status' => $status,
        'result' => $result,
        'error' => $errorCode ? 'something went wrong' : null,
        'error_code' => $errorCode,
        'provenance' => [
            'provider' => 'deepseek',
            'model_id' => 'deepseek-chat',
            'model_version' => 'deepseek-chat',
            'prompt_version' => $job->kind.'-v1',
            'kb_version_id' => $status === 'succeeded' ? test()->kbVersionId : null,
        ],
    ];
}

function citation(): array
{
    return ['source_id' => (string) Str::uuid(), 'article_ref' => 'المادة (5)', 'chunk_id' => (string) Str::uuid(), 'excerpt' => '...'];
}

function draftResult(): array
{
    $clauses = array_map(fn ($kind) => ['clause_kind' => $kind, 'content' => "بند {$kind}"], CLAUSE_KINDS);

    return ['body' => implode("\n\n", array_column($clauses, 'content')), 'clauses' => $clauses, 'citations' => [citation()]];
}

function analysisResult(): array
{
    return [
        'coverage' => array_map(fn ($kind) => [
            'clause_kind' => $kind,
            'status' => $kind === 'duration' ? 'incomplete' : 'present',
            'note' => 'ملاحظة.',
            'citations' => [],
        ], CLAUSE_KINDS),
        'findings' => [
            ['kind' => 'missing_clause', 'clause_kind' => 'duration', 'severity' => 'medium', 'title_ar' => 'بند غير مكتمل: مدة العقد',
                'title_en' => 'Incomplete clause: duration', 'description' => 'التاريخ فارغ.', 'suggested_text' => null, 'citations' => [], 'confidence' => 1.0],
            ['kind' => 'legal_conflict', 'clause_kind' => 'termination', 'severity' => 'high', 'title_ar' => 'تعارض',
                'title_en' => 'Conflict', 'description' => 'يخالف المادة (5).', 'suggested_text' => 'نص مقترح', 'citations' => [citation()], 'confidence' => 0.8],
        ],
        'risk_score' => 42,
        'risk_rubric_version' => 'risk-v2',
        'summary_ar' => 'ملخص',
        'summary_en' => 'Summary',
        'confidence' => 0.8,
    ];
}

/** Accept the request and pretend SendAiJob got its 202. */
function acceptedContract(): Contract
{
    test()->patchJson('/api/v1/lawyer/requests/'.test()->request->id.'/accept', [], actingAsUser(test()->lawyer))->assertCreated();

    $contract = Contract::firstOrFail();
    $contract->aiJobs()->update(['status' => 'running', 'dispatched_at' => now()]);

    return $contract;
}

function draftedContract(): Contract
{
    $contract = acceptedContract();
    postCallback(callback($contract->aiJobs()->firstOrFail(), 'succeeded', draftResult()))->assertNoContent();

    return $contract->fresh();
}

test('a lawyer accepting a request creates the contract and queues its draft', function () {
    $this->patchJson("/api/v1/lawyer/requests/{$this->request->id}/accept", [], actingAsUser($this->lawyer))
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.value', '450.000')
        ->assertJsonPath('data.ai_job.kind', 'generate_contract');

    expect($this->request->fresh()->status)->toBe('accepted')
        ->and($this->property->fresh()->status)->toBe('under_contract');

    $contract = Contract::firstOrFail();
    expect($contract->lawyer_id)->toBe($this->lawyer->id)
        ->and($contract->value_amount)->toBe(450000)
        ->and($contract->starts_on->toDateString())->toBe('2026-10-01');

    $job = AiJob::firstOrFail();
    expect($job->status)->toBe('queued')->and($job->contract_id)->toBe($contract->id);
    Queue::assertPushed(SendAiJob::class, fn ($sent) => $sent->aiJobId === $job->id);
});

test('only the assigned lawyer decides a request, and a rejection needs a reason', function () {
    $this->patchJson("/api/v1/lawyer/requests/{$this->request->id}/accept", [], actingAsUser($this->owner))->assertForbidden();
    $this->patchJson("/api/v1/lawyer/requests/{$this->request->id}/reject", [], actingAsUser($this->lawyer))->assertUnprocessable();

    $this->patchJson("/api/v1/lawyer/requests/{$this->request->id}/reject", ['reason' => 'Ownership unclear.'], actingAsUser($this->lawyer))
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect($this->request->fresh()->response_note)->toBe('Ownership unclear.')
        ->and(Contract::count())->toBe(0);
});

test('sending a draft job posts the contract terms and marks the job running', function () {
    $this->patchJson("/api/v1/lawyer/requests/{$this->request->id}/accept", [], actingAsUser($this->lawyer));
    $job = AiJob::firstOrFail();
    Http::fake(['ai.test/*' => Http::response(['job_id' => $job->id, 'status' => 'running'], 202)]);

    (new SendAiJob($job->id))->handle(app(AiJobService::class));

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://ai.test/v1/jobs'
        && $request['job_id'] === $job->id
        && $request['payload']['contract_type'] === 'rent'
        && $request['payload']['parties'][0]['role'] === 'landlord'
        && $request['payload']['parties'][0]['name'] === 'Owner Name'
        && $request['payload']['terms'] === [
            'price' => '450', 'currency' => 'JOD', 'price_unit' => 'per_month',
            'starts_on' => '2026-10-01', 'ends_on' => '2027-09-30',
        ]);
    expect($job->fresh()->status)->toBe('running')->and($job->fresh()->attempts)->toBe(1);
    Queue::assertPushed(CheckAiJobTimeout::class);
});

test('a job the AI refuses is failed, not retried', function () {
    $this->patchJson("/api/v1/lawyer/requests/{$this->request->id}/accept", [], actingAsUser($this->lawyer));
    $job = AiJob::firstOrFail();
    Http::fake(['ai.test/*' => Http::response(['detail' => 'bad kind'], 422)]);

    (new SendAiJob($job->id))->handle(app(AiJobService::class));

    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_code)->toBe('rejected_by_ai');
});

test('a callback needs a valid, fresh signature', function () {
    $job = acceptedContract()->aiJobs()->firstOrFail();
    $body = callback($job, 'succeeded', draftResult());

    $this->call('POST', '/api/v1/ai/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WATHIQ_SIGNATURE' => 't='.time().',v1='.str_repeat('0', 64),
    ], json_encode($body))->assertUnauthorized();
    postCallback($body, time() - 600)->assertUnauthorized();

    expect($job->fresh()->status)->toBe('running');
});

test('the signature check matches the AI service byte for byte', function () {
    // Same vector as Wathiq-ps/Ai tests: secret "test-secret", t=1760000000.
    $raw = '{"job_id":"00000000-0000-0000-0000-000000000001","status":"succeeded"}';

    expect('t=1760000000,v1='.hash_hmac('sha256', '1760000000.'.$raw, 'test-secret'))
        ->toBe('t=1760000000,v1=f864538279116e23481571ff51045794daa4a840a50041743956f19c8a4888eb');
});

test('a draft callback stores version 1 with its clauses, exactly once', function () {
    $contract = acceptedContract();
    $job = $contract->aiJobs()->firstOrFail();
    $body = callback($job, 'succeeded', draftResult());

    postCallback($body)->assertNoContent();
    postCallback($body, time() + 1)->assertNoContent(); // the AI's retry: same result, new signature

    $contract->refresh();
    expect($contract->versions()->count())->toBe(1)
        ->and($contract->status)->toBe('draft')
        ->and($contract->currentVersion->version_no)->toBe(1)
        ->and($contract->currentVersion->author_type)->toBe('ai')
        ->and($contract->currentVersion->clauses->pluck('kind')->all())->toBe(CLAUSE_KINDS);

    $job->refresh();
    expect($job->status)->toBe('succeeded')
        ->and($job->model_version)->toBe('deepseek-chat')
        ->and($job->kb_version_id)->toBe($this->kbVersionId)
        ->and($job->result['citations'])->toHaveCount(1);
});

test('an analysis of the current version reaches the lawyer, not the parties', function () {
    $contract = draftedContract();

    $this->postJson("/api/v1/contracts/{$contract->id}/analysis", [], actingAsUser($this->lawyer))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'under_ai_review');

    $job = $contract->aiJobs()->where('kind', 'analyze_contract')->firstOrFail();
    expect($job->contract_version_id)->toBe($contract->current_version_id);
    $job->update(['status' => 'running', 'dispatched_at' => now()]);

    postCallback(callback($job, 'succeeded', analysisResult()))->assertNoContent();

    expect($contract->fresh()->status)->toBe('pending_lawyer_review');

    $analysis = $this->getJson("/api/v1/contracts/{$contract->id}", actingAsUser($this->lawyer))
        ->assertOk()
        ->assertJsonPath('data.analysis.risk_band', 'medium')
        ->assertJsonCount(11, 'data.analysis.coverage')
        ->json('data.analysis');

    $findings = collect($analysis['findings'])->keyBy('clause_kind');
    $termination = $contract->currentVersion->clauses->firstWhere('kind', 'termination');
    expect($findings['termination']['clause_id'])->toBe($termination->id)
        ->and($findings['duration']['kind'])->toBe('missing_clause');

    $this->getJson("/api/v1/contracts/{$contract->id}", actingAsUser($this->owner))
        ->assertOk()
        ->assertJsonPath('data.current_version.version_no', 1)
        ->assertJsonMissingPath('data.analysis');
});

test('a failed analysis returns the contract to draft', function () {
    $contract = draftedContract();
    $this->postJson("/api/v1/contracts/{$contract->id}/analysis", [], actingAsUser($this->lawyer))->assertAccepted();
    $job = $contract->aiJobs()->where('kind', 'analyze_contract')->firstOrFail();
    $job->update(['status' => 'running']);

    postCallback(callback($job, 'failed', errorCode: 'llm_invalid_output'))->assertNoContent();

    expect($job->fresh()->status)->toBe('failed')
        ->and($job->fresh()->error_code)->toBe('llm_invalid_output')
        ->and($contract->fresh()->status)->toBe('draft');
});

test('a failed draft can be generated again, and only then', function () {
    $contract = acceptedContract();
    postCallback(callback($contract->aiJobs()->firstOrFail(), 'failed', errorCode: 'no_verified_sources'))->assertNoContent();

    $this->postJson("/api/v1/contracts/{$contract->id}/generation", [], actingAsUser($this->lawyer))->assertAccepted();
    $this->postJson("/api/v1/contracts/{$contract->id}/generation", [], actingAsUser($this->lawyer))->assertConflict();

    expect($contract->aiJobs()->count())->toBe(2);
});

test('a job with no callback times out, but a finished one is left alone', function () {
    $contract = acceptedContract();
    $job = $contract->aiJobs()->firstOrFail();

    (new CheckAiJobTimeout($job->id))->handle(app(AiJobService::class));
    expect($job->fresh()->status)->toBe('timed_out')->and($job->fresh()->error_code)->toBe('no_callback');

    AiJob::whereKey($job->id)->update(['status' => 'running', 'error_code' => null, 'completed_at' => null]);
    postCallback(callback($job, 'succeeded', draftResult()))->assertNoContent();
    (new CheckAiJobTimeout($job->id))->handle(app(AiJobService::class));
    expect($job->fresh()->status)->toBe('succeeded');
});
