<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which language a location request wants its names and sort order in:
 * ?lang= when given, else the best Accept-Language match, else the first
 * configured locale. Read the same way by the form request (to sort) and by
 * the resources (to pick `name`), so the two can't disagree.
 */
final class LocationLocale
{
    public static function fromRequest(Request $request): string
    {
        $supported = config('locations.locales');
        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, $supported, true)) {
            return $lang;
        }

        return $request->getPreferredLanguage($supported) ?? $supported[0];
    }
}
