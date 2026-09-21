<?php

namespace App\Http\Resources;

/**
 * Home-page "featured" widget. Identical shape to TopRatedPropertyResource
 * (public fields + average_rating/ratings_count from a rating aggregate) —
 * kept as its own class since "featured" and "top rated" are different
 * queries (curated flag vs. rating ranking) that happen to render the same.
 */
class FeaturedPropertyResource extends TopRatedPropertyResource {}
