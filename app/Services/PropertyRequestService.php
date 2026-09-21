<?php

namespace App\Services;

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Exceptions\Property\PropertyConflictException;
use App\Exceptions\Property\PropertyRequestNotAllowedException;
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

        $hasPending = PropertyRequest::where('property_id', $propertyId)
            ->where('requester_id', $requester->id)
            ->where('status', 'pending')
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
     * does not itself decide the request: status stays 'pending', and
     * lawyer_id being set is what marks it as "awaiting the lawyer" rather
     * than "awaiting the owner". The lawyer's own accept/reject is separate,
     * not-yet-built work.
     */
    public function accept(User $owner, PropertyRequest $propertyRequest, string $lawyerId): PropertyRequest
    {
        $property = $propertyRequest->property;

        if (! $property || $property->owner_id !== $owner->id) {
            throw AuthorizationFailedException::forbidden();
        }

        if ($propertyRequest->status !== 'pending') {
            abort(409, 'This request has already been responded to.');
        }

        if ($propertyRequest->lawyer_id !== null) {
            abort(409, 'This request has already been forwarded to a lawyer.');
        }

        $isVerifiedLawyer = DB::table('lawyer_credentials')
            ->where('user_id', $lawyerId)
            ->whereNotNull('verified_at')
            ->exists();

        if (! $isVerifiedLawyer) {
            abort(422, 'The selected lawyer is not a verified lawyer.');
        }

        $propertyRequest->forceFill(['lawyer_id' => $lawyerId])->save();

        return $propertyRequest;
    }

    private function generateReference(string $tenantId): string
    {
        do {
            $reference = 'RQ-'.strtoupper(Str::random(8));
        } while (PropertyRequest::where('tenant_id', $tenantId)->where('reference', $reference)->exists());

        return $reference;
    }
}
