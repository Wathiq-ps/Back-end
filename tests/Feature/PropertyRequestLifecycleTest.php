<?php

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\User;
use App\Services\PropertyRequestService;
use Database\Seeders\LawyerSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

if (getenv('DB_CONNECTION') !== 'pgsql') {
    test('property request lifecycle')->skip('Needs Postgres: set DB_CONNECTION=pgsql and DB_* to run these.');

    return;
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->seed([TenantSeeder::class, LawyerSeeder::class]);
    $this->lawyer = User::whereHas('lawyerCredential', fn ($q) => $q->whereNotNull('verified_at'))->firstOrFail();
    $this->owner = User::factory()->create();
    $this->property = Property::factory()->published()->create(['owner_id' => $this->owner->id, 'listing_type' => 'rent']);
    $this->service = app(PropertyRequestService::class);
    $this->makeRequest = function (array $attributes = []) {
        return PropertyRequest::create(array_merge([
            'tenant_id' => $this->property->tenant_id,
            'reference' => 'RQ-'.strtoupper(Str::random(8)),
            'property_id' => $this->property->id,
            'requester_id' => User::factory()->create()->id,
            'type' => 'rent',
            'term_start' => '2027-03-01',
            'term_end' => '2027-04-01',
            'status' => 'pending',
        ], $attributes));
    };
    $this->selected = ($this->makeRequest)();
    $this->other = ($this->makeRequest)();
});

function assertRequestConflict(Closure $action): void
{
    try {
        $action();
        test()->fail('Expected conflict');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
}

test('owner cannot accept or reject other requests while one is with the lawyer', function () {
    $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    assertRequestConflict(fn () => $this->service->accept($this->owner, $this->other, $this->lawyer->id));
    assertRequestConflict(fn () => $this->service->reject($this->owner, $this->other, 'No'));
    expect($this->other->fresh()->status)->toBe('pending');
});

test('requester cancellation releases selection and stale lawyer decisions cannot revive it', function () {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $this->service->cancel($selected->requester, $selected);
    assertRequestConflict(fn () => $this->service->lawyerAccept($this->lawyer, $selected));
    assertRequestConflict(fn () => $this->service->lawyerReject($this->lawyer, $selected, 'No'));
    $this->service->reject($this->owner, $this->other, 'No');
    $next = ($this->makeRequest)();
    expect($this->service->accept($this->owner, $next, $this->lawyer->id)->status)->toBe('pending_lawyer_review');
});

test('another user cannot cancel the selected request', function () {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    expect(fn () => $this->service->cancel(User::factory()->create(), $selected))
        ->toThrow(AuthorizationFailedException::class);
    expect($selected->fresh()->status)->toBe('pending_lawyer_review');
});

test('lawyer acceptance keeps other decisions locked until contract cancellation', function () {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $contract = $this->service->lawyerAccept($this->lawyer, $selected);
    assertRequestConflict(fn () => $this->service->accept($this->owner, $this->other, $this->lawyer->id));
    assertRequestConflict(fn () => $this->service->reject($this->owner, $this->other, 'No'));
    $this->service->cancel($this->owner, $selected);
    expect($contract->fresh()->status)->toBe('cancelled');
    expect($selected->fresh()->status)->toBe('cancelled')
        ->and($this->property->fresh()->status)->toBe('published');
    expect($this->service->accept($this->owner, $this->other, $this->lawyer->id)->status)->toBe('pending_lawyer_review');
});

test('database refuses two selected requests even when bypassing the service', function () {
    $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    expect(fn () => DB::transaction(fn () => $this->other->update([
        'status' => 'pending_lawyer_review', 'lawyer_id' => $this->lawyer->id,
    ])))->toThrow(QueryException::class);
});

test('rental activation cancels only overlapping open requests and permits same day handover', function () {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $contract = $this->service->lawyerAccept($this->lawyer, $selected);
    $overlaps = collect([
        ['2027-02-01', '2027-03-02'],
        ['2027-03-31', '2027-05-01'],
        ['2027-03-02', '2027-03-20'],
        ['2027-02-01', '2027-05-01'],
    ])->map(fn ($dates) => ($this->makeRequest)(['term_start' => $dates[0], 'term_end' => $dates[1]]));
    $untouched = collect([
        ['2027-02-01', '2027-03-01'],
        ['2027-04-01', '2027-05-01'],
    ])->map(fn ($dates) => ($this->makeRequest)(['term_start' => $dates[0], 'term_end' => $dates[1]]));
    $rejected = ($this->makeRequest)(['status' => 'rejected', 'responded_by' => $this->owner->id, 'responded_at' => now()]);
    $elsewhere = ($this->makeRequest)(['property_id' => Property::factory()->published()->create()->id]);

    foreach (['under_ai_review', 'pending_lawyer_review', 'approved', 'awaiting_signatures', 'fully_signed', 'awaiting_payment', 'active'] as $status) {
        $contract->update(['status' => $status]);
    }

    expect($this->property->fresh()->status)->toBe('rented')
        ->and($selected->fresh()->status)->toBe('accepted')
        ->and($this->other->fresh()->status)->toBe('cancelled')
        ->and($rejected->fresh()->status)->toBe('rejected')
        ->and($elsewhere->fresh()->status)->toBe('pending');
    foreach ($overlaps as $request) {
        expect($request->fresh()->status)->toBe('cancelled');
    }
    foreach ($untouched as $request) {
        expect($request->fresh()->status)->toBe('pending');
    }
});

test('lawyer rejection releases the property for another owner decision', function () {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $this->service->lawyerReject($this->lawyer, $selected, 'Unable to take this assignment.');
    expect($this->service->accept($this->owner, $this->other, $this->lawyer->id)->status)->toBe('pending_lawyer_review');
});

test('marking a property rented uses the agreed contract dates', function () {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $contract = $this->service->lawyerAccept($this->lawyer, $selected);
    $contract->update(['starts_on' => '2027-05-01', 'ends_on' => '2027-06-01']);
    $overlap = ($this->makeRequest)(['term_start' => '2027-05-15', 'term_end' => '2027-06-15']);
    $this->property->update(['status' => 'rented']);
    expect($overlap->fresh()->status)->toBe('cancelled')
        ->and($this->other->fresh()->status)->toBe('pending');
});

test('selection also blocks other purchase requests', function () {
    $this->property->update(['listing_type' => 'sale']);
    foreach ([$this->selected, $this->other] as $request) {
        $request->update(['type' => 'sale', 'term_start' => null, 'term_end' => null]);
    }
    $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    assertRequestConflict(fn () => $this->service->reject($this->owner, $this->other, 'No'));
    assertRequestConflict(fn () => $this->service->accept($this->owner, $this->other, $this->lawyer->id));
});

test('either party can cancel any request status without a fully approved contract', function (string $status, string $party) {
    $this->selected->forceFill([
        'status' => $status,
        'responded_by' => $this->owner->id,
        'responded_at' => now(),
        'lawyer_id' => $this->lawyer->id,
    ])->save();
    $actor = $party === 'owner' ? $this->owner : $this->selected->requester;
    expect($this->service->cancel($actor, $this->selected)->status)->toBe('cancelled');
})->with(['pending', 'pending_lawyer_review', 'accepted', 'rejected', 'cancelled', 'closed', 'expired'])
    ->with(['owner', 'requester']);

test('one party approval does not prevent cancellation of the contract and request', function (string $party) {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $contract = $this->service->lawyerAccept($this->lawyer, $selected);
    foreach (['under_ai_review', 'pending_lawyer_review', 'approved'] as $status) {
        $contract->update(['status' => $status]);
    }
    $contract->update([$party === 'owner' ? 'owner_approved_at' : 'beneficiary_approved_at' => now()]);
    $actor = $party === 'owner' ? $this->owner : $selected->requester;
    $this->service->cancel($actor, $selected);
    expect($contract->fresh()->status)->toBe('cancelled')
        ->and($selected->fresh()->status)->toBe('cancelled')
        ->and($contract->aiJobs()->first()->status)->toBe('cancelled')
        ->and($this->property->fresh()->status)->toBe('published');
})->with(['owner', 'requester']);

test('all three contract approvals prevent either party cancelling even with a stale request', function (string $party) {
    $selected = $this->service->accept($this->owner, $this->selected, $this->lawyer->id);
    $contract = $this->service->lawyerAccept($this->lawyer, $selected);
    foreach (['under_ai_review', 'pending_lawyer_review', 'approved'] as $status) {
        $contract->update(['status' => $status]);
    }
    $contract->update(['owner_approved_at' => now(), 'beneficiary_approved_at' => now()]);
    $actor = $party === 'owner' ? $this->owner : $selected->requester;
    assertRequestConflict(fn () => $this->service->cancel($actor, $selected));
    expect($contract->fresh()->status)->toBe('approved')
        ->and($selected->fresh()->status)->toBe('accepted')
        ->and($this->property->fresh()->status)->toBe('under_contract');
})->with(['owner', 'requester']);
