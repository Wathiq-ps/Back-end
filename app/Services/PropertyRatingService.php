<?php

namespace App\Services;

use App\Exceptions\Property\PropertyConflictException;
use App\Exceptions\Property\PropertyRatingNotAllowedException;
use App\Models\Property;
use App\Models\PropertyRating;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * There's no Contract Eloquent model yet (Module 6 business logic isn't
 * built), so eligibility is checked straight against app.contracts via the
 * query builder — same posture as PropertyService::currencyExponent()
 * reading app.currencies.
 */
class PropertyRatingService
{
    public function create(User $rater, string $propertyId, array $data): PropertyRating
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');
        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $property = Property::where('id', $propertyId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $property) {
            abort(404);
        }

        $completedContractIds = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->where('beneficiary_id', $rater->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->pluck('id');

        if ($completedContractIds->isEmpty()) {
            throw PropertyRatingNotAllowedException::noCompletedContract();
        }

        $ratedContractIds = PropertyRating::whereIn('contract_id', $completedContractIds)->pluck('contract_id');

        $contractId = $completedContractIds->first(fn ($id) => ! $ratedContractIds->contains($id));

        if (! $contractId) {
            throw PropertyConflictException::alreadyRated();
        }

        return PropertyRating::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'contract_id' => $contractId,
            'rater_id' => $rater->id,
            'score' => $data['score'],
            'comment' => $data['comment'] ?? null,
        ]);
    }
}
