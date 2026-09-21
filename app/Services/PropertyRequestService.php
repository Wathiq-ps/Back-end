<?php

namespace App\Services;

use App\Exceptions\Property\PropertyConflictException;
use App\Exceptions\Property\PropertyRequestNotAllowedException;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\Tenant;
use App\Models\User;
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

    private function generateReference(string $tenantId): string
    {
        do {
            $reference = 'RQ-'.strtoupper(Str::random(8));
        } while (PropertyRequest::where('tenant_id', $tenantId)->where('reference', $reference)->exists());

        return $reference;
    }
}
