<?php

namespace Database\Seeders;

use App\Ai\WireContract;
use App\Models\AiJob;
use App\Models\Contract;
use App\Models\OwnershipDocument;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AiJobService;
use App\Services\ContractReviewService;
use App\Support\Jurisdiction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Contracts in every app.contract_status — and the in-between states the
 * clients draw differently (the AI still drafting, a failed draft, one
 * party's approval in, a party's rejection) — each once as a rent and once
 * as a sale.
 *
 * All of them are between the same owner and beneficiary, with the seeded
 * lawyer, so one login sees the lot. Those two accounts' mailboxes don't
 * exist: get a token with `php artisan user:token owner@wathiq.test`.
 *
 * Each contract gets where it is the way a real one does: the AI's answers go
 * through AiJobService::apply() and the lawyer's and parties' steps through
 * ContractReviewService, so versions, findings and contract_status_history
 * (who moved it, and why) look like the real thing. Signing, payment and
 * handover have no code yet, so those are bare status moves, with no
 * signature or payment rows behind them. Nothing is sent to the AI service:
 * the jobs are written already answered.
 *
 * Not idempotent: each run adds another set, on new properties.
 */
class ContractSeeder extends Seeder
{
    private const OWNER_EMAIL = 'owner@wathiq.test';

    private const BENEFICIARY_EMAIL = 'beneficiary@wathiq.test';

    /** The clauses a rent has and a sale doesn't (WireContract::CLAUSE_KINDS). */
    private const LEASE_KINDS = ['deposit', 'utilities', 'maintenance', 'handover', 'inspection'];

    private User $lawyer;

    private User $owner;

    private User $beneficiary;

    private string $kbVersionId;

    public function __construct(
        private readonly AiJobService $ai,
        private readonly ContractReviewService $review,
    ) {}

    public function run(): void
    {
        // DatabaseSeeder runs WithoutModelEvents, which also silences the
        // `creating` hook HasUuidPrimaryKey sets ids from — and the services
        // used here read those ids straight back.
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));

        try {
            $this->prepare();

            foreach ($this->scenarios() as $steps) {
                foreach (['rent', 'sale'] as $type) {
                    $contract = $this->newContract($type);

                    foreach ($steps as $step) {
                        $this->step($contract->fresh(), $step);
                    }

                    $this->settleProperty($contract->fresh());
                }
            }
        } finally {
            $events ? Model::setEventDispatcher($events) : Model::unsetEventDispatcher();
        }

        $count = count($this->scenarios()) * 2;
        $this->command?->info("{$count} contracts seeded between ".self::OWNER_EMAIL.' and '.self::BENEFICIARY_EMAIL
            .", with {$this->lawyer->email} as their lawyer.");
    }

    /** @return array<string, list<string>> what each contract went through, by what it shows */
    private function scenarios(): array
    {
        $analysed = ['drafted', 'analysed'];
        $approved = [...$analysed, 'findings_decided', 'lawyer_approved'];
        $agreed = [...$approved, 'owner_approved', 'beneficiary_approved'];
        $signed = [...$agreed, 'awaiting_signatures', 'fully_signed'];
        $active = [...$signed, 'awaiting_payment', 'active'];

        return [
            'AI is drafting' => ['drafting'],
            'AI draft failed' => ['draft_failed'],
            'drafted, not yet analysed' => ['drafted'],
            'AI is analysing' => ['drafted', 'analysing'],
            'pending lawyer review' => $analysed,
            'lawyer edited the draft' => [...$analysed, 'lawyer_edited'],
            'sent back by the lawyer' => [...$analysed, 'sent_back_by_lawyer'],
            'approved by the lawyer' => $approved,
            'approved by the owner' => [...$approved, 'owner_approved'],
            'approved by both parties' => $agreed,
            'rejected by the owner' => [...$approved, 'owner_rejected'],
            'rejected by the beneficiary' => [...$approved, 'owner_approved', 'beneficiary_rejected'],
            'revised and re-approved after a rejection' => [...$approved, 'beneficiary_rejected', 'lawyer_edited', 'lawyer_approved'],
            'awaiting signatures' => [...$agreed, 'awaiting_signatures'],
            'fully signed' => $signed,
            'awaiting payment' => [...$signed, 'awaiting_payment'],
            'active' => $active,
            'completed' => [...$active, 'completed'],
            'expired' => [...$agreed, 'awaiting_signatures', 'expired'],
            'cancelled as a draft' => ['drafted', 'cancelled'],
            'cancelled after approval' => [...$approved, 'cancelled'],
        ];
    }

    private function step(Contract $contract, string $step): void
    {
        match ($step) {
            'drafting' => $this->job($contract, 'generate_contract'),
            'draft_failed' => $this->job($contract, 'generate_contract', 'failed'),
            'drafted' => $this->ai->apply($this->job($contract, 'generate_contract'), $this->answer('generate_contract', $this->draft($contract))),
            'analysing' => $this->submitForAnalysis($contract),
            'analysed' => $this->ai->apply($this->submitForAnalysis($contract), $this->answer('analyze_contract', $this->analysis($contract))),
            'findings_decided' => $this->decideFindings($contract),
            'lawyer_edited' => $this->review->saveVersion($this->lawyer, $contract, [
                $contract->currentVersion->clauses->firstWhere('kind', 'payment_terms')->ordinal => 'يُدفع المبلغ على دفعتين متساويتين: الأولى عند التوقيع، والثانية بعد ثلاثين يومًا.',
            ], 'عدّلت بند الدفع ليكون على دفعتين.'),
            'sent_back_by_lawyer' => $this->review->requestModification($this->lawyer, $contract, 'يرجى من المالك تزويدنا برقم القطعة والحوض لإكمال وصف العقار.'),
            'lawyer_approved' => $this->review->approve($this->lawyer, $contract),
            'owner_approved' => $this->review->approveAsParty($this->owner, $contract),
            'beneficiary_approved' => $this->review->approveAsParty($this->beneficiary, $contract),
            'owner_rejected' => $this->review->rejectAsParty($this->owner, $contract, 'أرجو أن يكون الدفع على دفعتين بدلًا من دفعة واحدة.'),
            'beneficiary_rejected' => $this->review->rejectAsParty($this->beneficiary, $contract, 'أرجو إضافة مهلة إنذار مدتها ثلاثون يومًا قبل الفسخ.'),
            'awaiting_signatures' => $this->move($contract, 'awaiting_signatures', $this->lawyer),
            'cancelled' => $this->move($contract, 'cancelled', $this->lawyer, 'انسحب الطرفان من الصفقة.'),
            // Signed, issued, paid, handed over, lapsed: no code moves these yet.
            default => $this->move($contract, $step),
        };
    }

    private function prepare(): void
    {
        if (! Tenant::where('slug', 'default')->exists()) {
            throw new RuntimeException('No default tenant found. Run TenantSeeder first.');
        }

        $jurisdictionId = Jurisdiction::defaultId()
            ?? throw new RuntimeException('No jurisdiction found. Run JurisdictionSeeder first.');

        if (! $this->approvedLawyer()) {
            $this->call(LawyerSeeder::class);
        }
        $this->lawyer = $this->approvedLawyer();

        $this->owner = $this->party(self::OWNER_EMAIL, 'مالك تجريبي');
        $this->beneficiary = $this->party(self::BENEFICIARY_EMAIL, 'مستفيد تجريبي');

        // ai_jobs_success_has_provenance wants a knowledge-base version on
        // every answered job. A version of its own, never 'active', so the
        // AI service's retrieval never serves it.
        $kbVersion = ['jurisdiction_id' => $jurisdictionId, 'tag' => 'seed-fixture'];
        $this->kbVersionId = DB::table('knowledge.kb_versions')->where($kbVersion)->value('id')
            ?? DB::table('knowledge.kb_versions')->insertGetId([
                ...$kbVersion,
                'embedding_model' => 'seed-fixture',
                'embedding_dimensions' => 1536,
                'status' => 'superseded',
                'notes' => 'ContractSeeder: the provenance of its seeded AI jobs. Holds no chunks.',
            ]);
    }

    private function approvedLawyer(): ?User
    {
        return User::whereHas('lawyerCredential', fn ($query) => $query->where('status', 'approved'))->first();
    }

    /** An existing account is used as it is; a new one is created ready to use the contract endpoints. */
    private function party(string $email, string $name): User
    {
        if ($user = User::where('email', $email)->first()) {
            if (! $user->isKycVerified()) {
                $this->command?->warn("{$email} is not KYC-approved: the contract endpoints will answer it 403 kyc_required.");
            }

            return $user;
        }

        $user = User::factory()->create([
            'email' => $email,
            'name' => $name,
            'nationality' => 'Palestinian',
            'document_type' => 'national_id',
            'document_number' => (string) random_int(400000000, 499999999),
            'signature_path' => 'signatures/seed.png',
        ]);
        $tenantId = Tenant::where('slug', 'default')->value('id');

        TenantMembership::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => Role::where('code', 'user')->value('id'),
            'status' => 'active',
        ]);

        // Identity KYC, approved — skipping the real review flow, as
        // LawyerSeeder does.
        DB::table('identity_documents')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'type' => $user->document_type,
            'document_number' => $user->document_number,
            'front_path' => "kyc/{$user->id}/front.jpg",
            'selfie_path' => "kyc/{$user->id}/selfie.jpg",
            'status' => 'approved',
            'reviewed_by' => $this->lawyer->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * What PropertyRequestService::lawyerAccept() leaves behind, minus the AI
     * job it queues: a listing under contract, the accepted request, and the
     * contract in draft. Each contract has a property of its own — a
     * property takes one accepted request at a time.
     */
    private function newContract(string $type): Contract
    {
        $rent = $type === 'rent';
        $propertyType = fake()->randomElement(['apartment', 'house', 'villa', 'office', 'shop']);

        $property = Property::factory()->underContract()->create([
            'owner_id' => $this->owner->id,
            'title' => ucfirst($propertyType).($rent ? ' for rent' : ' for sale'),
            'type' => $propertyType,
            'listing_type' => $type,
            // ILS has 2 decimals and JOD 3, so both minor-unit paths show up.
            'price_currency' => $rent ? 'ILS' : 'JOD',
            'price_amount' => $rent ? fake()->numberBetween(15, 40) * 10000 : fake()->numberBetween(50, 250) * 1000000,
            'price_unit' => $rent ? 'per_month' : null,
        ]);

        OwnershipDocument::create([
            'tenant_id' => $property->tenant_id,
            'property_id' => $property->id,
            'uploaded_by' => $this->owner->id,
            'type' => 'title_deed',
            'path' => "ownership/{$property->id}/seed.pdf",
            'checksum' => hash('sha256', $property->id),
            'status' => 'approved',
            'reviewed_by' => $this->lawyer->id,
            'reviewed_at' => now(),
        ]);

        $startsOn = $rent ? today()->startOfMonth() : null;
        $request = PropertyRequest::create([
            'tenant_id' => $property->tenant_id,
            'reference' => 'RQ-'.strtoupper(Str::random(8)),
            'property_id' => $property->id,
            'requester_id' => $this->beneficiary->id,
            'lawyer_id' => $this->lawyer->id,
            'type' => $type,
            'term_start' => $startsOn,
            'term_end' => $startsOn?->copy()->addYear()->subDay(),
            'status' => 'accepted',
            'responded_by' => $this->lawyer->id,
            'responded_at' => now(),
        ]);

        return Contract::create([
            'tenant_id' => $request->tenant_id,
            'reference' => 'CT-'.strtoupper(Str::random(8)),
            'request_id' => $request->id,
            'property_id' => $property->id,
            'owner_id' => $this->owner->id,
            'beneficiary_id' => $this->beneficiary->id,
            'lawyer_id' => $this->lawyer->id,
            'type' => $type,
            'status' => 'draft',
            'jurisdiction_id' => Jurisdiction::defaultId(),
            'value_amount' => $property->price_amount,
            'value_currency' => $property->price_currency,
            'starts_on' => $request->term_start,
            'ends_on' => $request->term_end,
            'created_by' => $this->lawyer->id,
        ]);
    }

    /** A job the AI has accepted and not answered yet — or, given 'failed', answered with a failure. */
    private function job(Contract $contract, string $kind, string $status = 'running'): AiJob
    {
        return AiJob::create([
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'contract_version_id' => $kind === 'analyze_contract' ? $contract->current_version_id : null,
            'kind' => $kind,
            'status' => $status,
            'requested_by' => $this->lawyer->id,
            'attempts' => 1,
            'dispatched_at' => now(),
            ...($status === 'failed' ? [
                'error_code' => 'llm_invalid_output',
                'error_message' => 'The model returned a draft that failed validation.',
                'completed_at' => now(),
            ] : []),
        ]);
    }

    /** ContractService::submitForAnalysis(), minus sending the job. */
    private function submitForAnalysis(Contract $contract): AiJob
    {
        $this->move($contract, 'under_ai_review', $this->lawyer);

        return $this->job($contract, 'analyze_contract');
    }

    /** A succeeded callback, as the AI service would sign and send it. */
    private function answer(string $kind, array $result): array
    {
        return [
            'kind' => $kind,
            'status' => 'succeeded',
            'result' => $result,
            'provenance' => [
                'provider' => 'deepseek',
                'model_id' => 'deepseek-chat',
                'model_version' => 'deepseek-chat',
                'prompt_version' => "{$kind}-v1",
                'kb_version_id' => $this->kbVersionId,
            ],
            'usage' => ['prompt_tokens' => fake()->numberBetween(1500, 4000), 'completion_tokens' => fake()->numberBetween(600, 2000)],
        ];
    }

    private function draft(Contract $contract): array
    {
        $rent = $contract->type === 'rent';
        $property = $contract->property;
        $price = $contract->valueMajor().' '.$contract->value_currency;
        $texts = [
            'parties' => "الطرف الأول: {$contract->owner->name}، والطرف الثاني: {$contract->beneficiary->name}.",
            'subject' => ($rent ? 'يؤجر الطرف الأول للطرف الثاني' : 'يبيع الطرف الأول للطرف الثاني')." العقار الكائن في {$property->address_line}.",
            'price' => ($rent ? "الأجرة الشهرية {$price}." : "ثمن البيع {$price}."),
            'payment_terms' => $rent ? 'تُدفع الأجرة مقدمًا في أول كل شهر.' : 'يُدفع الثمن دفعة واحدة عند التوقيع.',
            'deposit' => 'يدفع المستأجر تأمينًا يعادل أجرة شهرين يُرد عند انتهاء الإجارة.',
            'duration' => $rent
                ? "مدة الإجارة من {$contract->starts_on->toDateString()} إلى {$contract->ends_on->toDateString()}."
                : 'تُنقل الملكية لدى دائرة تسجيل الأراضي خلال ثلاثين يومًا من التوقيع.',
            'obligations' => 'يلتزم كل طرف بتنفيذ التزاماته بحسن نية.',
            'utilities' => 'يتحمل المستأجر فواتير الماء والكهرباء.',
            'maintenance' => 'يتحمل المؤجر الصيانة الإنشائية، ويتحمل المستأجر الصيانة البسيطة.',
            'handover' => 'يُسلَّم العقار للمستأجر في تاريخ بدء الإجارة.',
            'inspection' => 'يحق للمؤجر معاينة العقار بعد إشعار مسبق مدته ثمانٍ وأربعون ساعة.',
            'warranties' => 'يضمن الطرف الأول خلو العقار من أي حقوق للغير.',
            'termination' => 'يجوز فسخ العقد عند إخلال أي طرف بالتزاماته.',
            'dispute_resolution' => "تختص محاكم {$property->city} بالنظر في أي نزاع ينشأ عن هذا العقد.",
            'governing_law' => 'يخضع هذا العقد للقوانين النافذة في فلسطين.',
            'other' => 'حُرر هذا العقد من نسختين بيد كل طرف نسخة للعمل بموجبها.',
        ];

        $clauses = collect(WireContract::CLAUSE_KINDS)
            ->reject(fn ($kind) => ! $rent && in_array($kind, self::LEASE_KINDS, true))
            ->map(fn ($kind) => ['clause_kind' => $kind, 'content' => $texts[$kind], 'citations' => []])
            ->values()->all();

        return ['body' => implode("\n\n", array_column($clauses, 'content')), 'clauses' => $clauses];
    }

    private function analysis(Contract $contract): array
    {
        $ordinals = $contract->currentVersion->clauses->pluck('ordinal', 'kind');
        $finding = fn (string $kind, string $clauseKind, ?int $ordinal, string $severity, string $titleAr, string $titleEn, string $description, ?string $suggested) => [
            'kind' => $kind, 'clause_kind' => $clauseKind, 'ordinal' => $ordinal, 'severity' => $severity,
            'title_ar' => $titleAr, 'title_en' => $titleEn, 'description' => $description,
            'suggested_text' => $suggested, 'citations' => [], 'confidence' => 0.8,
        ];

        return [
            'coverage' => $ordinals->keys()->map(fn ($kind) => [
                'clause_kind' => $kind,
                'status' => $kind === 'termination' ? 'incomplete' : 'present',
                'note' => $kind === 'termination' ? 'لا يحدد البند مهلة الإنذار.' : 'البند مستوفى.',
                'citations' => [],
            ])->all(),
            'findings' => [
                $finding('ambiguity', 'termination', $ordinals['termination'], 'medium', 'شروط الفسخ غامضة', 'Termination terms are vague',
                    'لا يحدد البند مهلة إنذار قبل الفسخ.', 'يجوز فسخ العقد عند إخلال أي طرف بالتزاماته بعد إنذاره خطيًا ومنحه مهلة ثلاثين يومًا.'),
                $finding('suggestion', 'payment_terms', $ordinals['payment_terms'], 'low', 'لا جزاء على التأخر في الدفع', 'No late-payment terms',
                    'لا يبين البند ما يترتب على التأخر في الدفع.', null),
                $finding('risk', 'other', null, 'high', 'وصف العقار ناقص', 'Incomplete property description',
                    'لا يذكر العقد رقم القطعة والحوض.', null),
            ],
            'risk_score' => fake()->numberBetween(20, 65),
            'risk_rubric_version' => 'risk-v3',
            'summary_ar' => 'العقد مكتمل في معظمه، ويحتاج إلى توضيح شروط الفسخ ووصف العقار.',
            'summary_en' => 'Mostly complete; the termination terms and the property description need work.',
            'confidence' => 0.8,
        ];
    }

    /** The lawyer accepts some findings and rejects the rest, with a reason. */
    private function decideFindings(Contract $contract): void
    {
        foreach ($contract->latestAnalysis->findings()->where('resolution', 'open')->get()->values() as $i => $finding) {
            $accepted = $i % 2 === 0;
            $this->review->resolveFinding($this->lawyer, $contract, $finding,
                $accepted ? 'accepted' : 'rejected', $accepted ? null : 'لا ينطبق على هذا العقد.');
        }
    }

    /** A status move the way ContractReviewService makes one: contract_status_history records who, and why. */
    private function move(Contract $contract, string $to, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($contract, $to, $actor, $reason) {
            DB::select(
                "select set_config('wathiq.actor_id', ?, true), set_config('wathiq.transition_reason', ?, true)",
                [(string) $actor?->id, (string) $reason],
            );

            // contracts_cancelled_has_reason.
            $contract->forceFill(['status' => $to, ...($to === 'cancelled' ? ['cancellation_reason' => $reason] : [])])->save();
        });
    }

    /** A finished contract lets go of its listing: sold or rented once completed, back on the market otherwise. */
    private function settleProperty(Contract $contract): void
    {
        $status = match ($contract->status) {
            'completed' => $contract->type === 'rent' ? 'rented' : 'sold',
            'cancelled', 'expired' => 'published',
            default => null,
        };

        if ($status) {
            $contract->property->forceFill(['status' => $status])->save();
        }
    }
}
