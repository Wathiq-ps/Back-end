<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Home-page "top rated" widget. Same public shape as PropertySearchResource
 * plus the aggregate that earned the property its spot on the list.
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
        ]);
    }
}
