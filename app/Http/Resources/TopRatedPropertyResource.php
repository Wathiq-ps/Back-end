<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Home-page "top rated" widget. Same public shape as PropertySearchResource
 * plus the aggregate that earned the property its spot on the list, and a
 * public owner card (see homeRelations() in PropertyController).
 * average_rating/ratings_count only exist on rows PropertyController::topRated()
 * selected via its aggregate (rated) query — a backfilled "newest" row never
 * carries them, so null here means "not yet rated", not "rated zero".
 */
class TopRatedPropertyResource extends PropertySearchResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'average_rating' => $this->average_rating !== null ? round((float) $this->average_rating, 2) : null,
            'ratings_count' => $this->ratings_count !== null ? (int) $this->ratings_count : 0,

            // Public-facing owner card. Contact details (email/phone) and KYC
            // document fields stay out on purpose: this endpoint is
            // unauthenticated, and contact goes through the request flow.
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'is_verified' => (bool) $this->owner->kyc_verified,
                'member_since' => $this->owner->created_at?->toDateString(),
            ] : null),
        ]);
    }
}
