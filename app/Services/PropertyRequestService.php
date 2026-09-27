<?php

namespace App\Services;

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Exceptions\Property\PropertyConflictException;
use App\Exceptions\Property\PropertyRequestNotAllowedException;
use App\Models\Contract;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UC-020/UC-021/UC-006/UC-007-shaped: a beneficiary submits a request to buy
 * or rent. Everything the DB already enforces structurally
 * (app.property_requests: one pending request per requester, requester !=
 * owner) is also checked here first, so a violation comes back as a clean
 * 4xx instead of a constraint-violation 500.
 */
class PropertyRequestService
{
    public function __construct(private readonly ContractService $contracts) {}

    public function submit(User $requester, string $propertyId, array $data): PropertyRequest
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');
        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $property = Property::where('id', $propertyId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $property) {
            abort(404);
        }

        if ($property->status !== 'published') {
            abort(400, 'This property is not currently accepting requests.');
        }

        if ($property->owner_id === $requester->id) {
            throw PropertyRequestNotAllowedException::cannotRequestOwnProperty();
        }

        // Both statuses are a live request from this requester — one waiting
        // on the owner, one already with the lawyer. Neither leaves room for
        // a second request on the same property.
        $hasPending = PropertyRequest::where('property_id', $propertyId)
            ->where('requester_id', $requester->id)
            ->whereIn('status', ['pending', 'pending_lawyer_review'])
            ->exists();

        if ($hasPending) {
            throw PropertyConflictException::duplicatePendingRequest();
        }

        return PropertyRequest::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'reference' => $this->generateReference($tenantId),
            'property_id' => $property->id,
            'requester_id' => $requester->id,
            'type' => $property->listing_type,
            'term_start' => $data['term_start'] ?? null,
            'term_end' => $data['term_end'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);
    }

    /**
     * The owner rejects a pending request against one of their own
     * properties. A reason is mandatory — requests_response_complete
     * doesn't require it (that's response_note, not responded_by/at), but
     * the owner-facing product rule is stricter than the DB constraint.
     */
    public function reject(User $owner, PropertyRequest $propertyRequest, string $reason): PropertyRequest
    {
        $property = $propertyRequest->property;

        if (! $property || $property->owner_id !== $owner->id) {
            throw AuthorizationFailedException::forbidden();
        }

        if ($propertyRequest->status !== 'pending') {
            abort(409, 'This request has already been responded to.');
        }

        $propertyRequest->forceFill([
            'status' => 'rejected',
            'responded_by' => $owner->id,
            'responded_at' => now(),
            'response_note' => $reason,
        ])->save();

        return $propertyRequest;
    }

    /**
     * The owner accepts a pending request by handing it to a lawyer — this
     * does not itself decide the request. It moves to
     * 'pending_lawyer_review', which is what separates "waiting on the
     * lawyer" from the plain 'pending' that means "waiting on the owner".
     * The lawyer's own accept/reject is separate, not-yet-built work.
     */
    public function accept(User $owner, PropertyRequest $propertyRequest, string $lawyerId): PropertyRequest
    {
        $property = $propertyRequest->property;

        if (! $property || $property->owner_id !== $owner->id) {
            throw AuthorizationFailedException::forbidden();
        }

        if ($propertyRequest->status === 'pending_lawyer_review') {
            abort(409, 'This request has already been forwarded to a lawyer.');
        }

        if ($propertyRequest->status !== 'pending') {
            abort(409, 'This request has already been responded to.');
        }

        $isVerifiedLawyer = DB::table('lawyer_credentials')
            ->where('user_id', $lawyerId)
            ->whereNotNull('verified_at')
            ->exists();

        if (! $isVerifiedLawyer) {
            abort(422, 'The selected lawyer is not a verified lawyer.');
        }

        $propertyRequest->forceFill([
            'status' => 'pending_lawyer_review',
            'lawyer_id' => $lawyerId,
        ])->save();

        return $propertyRequest;
    }

    /**
     * The assigned lawyer takes the request on. This is what decides it: the
     * request is accepted, the property leaves the market, and the contract
     * is created and handed to the AI for drafting — all or nothing.
     */
    public function lawyerAccept(User $lawyer, PropertyRequest $propertyRequest): Contract
    {
        $this->assertAwaitingLawyer($lawyer, $propertyRequest);

        // requests_one_accepted_per_property would refuse this anyway; checking
        // first makes it a 409 instead of a constraint-violation 500.
        $alreadyAccepted = PropertyRequest::where('property_id', $propertyRequest->property_id)
            ->where('status', 'accepted')
            ->exists();

        if ($alreadyAccepted) {
            abort(409, 'Another request on this property has already been accepted.');
        }

        return DB::transaction(function () use ($lawyer, $propertyRequest) {
            $propertyRequest->forceFill([
                'status' => 'accepted',
                'responded_by' => $lawyer->id,
                'responded_at' => now(),
            ])->save();

            $propertyRequest->property->forceFill(['status' => 'under_contract'])->save();

            return $this->contracts->createFromRequest($lawyer, $propertyRequest);
        });
    }

    /** The assigned lawyer turns the request down. Final, with a reason. */
    public function lawyerReject(User $lawyer, PropertyRequest $propertyRequest, string $reason): PropertyRequest
    {
        $this->assertAwaitingLawyer($lawyer, $propertyRequest);

        $propertyRequest->forceFill([
            'status' => 'rejected',
            'responded_by' => $lawyer->id,
            'responded_at' => now(),
            'response_note' => $reason,
        ])->save();

        return $propertyRequest;
    }

    private function assertAwaitingLawyer(User $lawyer, PropertyRequest $propertyRequest): void
    {
        if ($propertyRequest->lawyer_id !== $lawyer->id) {
            throw AuthorizationFailedException::forbidden();
        }

        if ($propertyRequest->status !== 'pending_lawyer_review') {
            abort(409, 'This request is not waiting for your decision.');
        }
    }

    private function generateReference(string $tenantId): string
    {
        do {
            $reference = 'RQ-'.strtoupper(Str::random(8));
        } while (PropertyRequest::where('tenant_id', $tenantId)->where('reference', $reference)->exists());

        return $reference;
    }
}
