<?php

namespace App\Services\Locations\Import;

use App\Models\Country;
use App\Services\Locations\LocationCache;
use Illuminate\Support\Facades\DB;
use Locale;

/**
 * Countries from GeoNames countryInfo.txt (ISO codes, English name, calling
 * code), with Arabic names from the CLDR data bundled in PHP's intl/ICU.
 *
 * REST Countries is not used: its current API needs a paid-plan key for
 * anything but prototyping, and its terms forbid keeping the data longer
 * than three days — incompatible with a stored table.
 *
 * Existing rows keep their names: a row that is already there — the
 * migration-seeded Palestine row, or anything an admin corrected — is never
 * renamed by an import, only filled in where a value is missing. Both
 * sources name PS "Palestinian Territory", for one; the seeded row says
 * "Palestine" and stays that way.
 */
class CountryImporter
{
    public function __construct(
        private readonly GeoNamesClient $geonames,
        private readonly LocationCache $cache,
    ) {}

    public function import(?string $path = null): ImportReport
    {
        $report = new ImportReport;
        $excluded = config('locations.import.geonames.excluded_countries');

        DB::transaction(function () use ($path, $excluded, $report) {
            $existing = Country::query()->get()->keyBy('iso2');

            foreach ($this->geonames->countries($path) as $row) {
                $iso2 = strtoupper(trim($row['iso2']));
                $iso3 = strtoupper(trim($row['iso3']));
                $name = trim($row['name']);

                if (in_array($iso2, $excluded, true)) {
                    $report->skip('dissolved country');

                    continue;
                }

                if (! preg_match('/^[A-Z]{2}$/', $iso2) || ! preg_match('/^[A-Z]{3}$/', $iso3) || $name === '') {
                    $report->skip('missing code or name');

                    continue;
                }

                $incoming = [
                    'iso3' => $iso3,
                    'name_en' => $name,
                    'name_ar' => $this->arabicName($iso2),
                    'dial_code' => $this->dialCode($row['phone']),
                ];

                $country = $existing->get($iso2);

                if (! $country) {
                    Country::query()->insert(['iso2' => $iso2, ...$incoming]);
                    $report->created++;

                    continue;
                }

                $fill = array_filter(
                    ['name_ar' => $incoming['name_ar'], 'dial_code' => $incoming['dial_code']],
                    fn ($value, $column) => $value !== null && blank($country->{$column}),
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($fill === []) {
                    $report->unchanged++;

                    continue;
                }

                Country::query()->whereKey($country->id)->update($fill);
                $report->updated++;
            }
        });

        $this->cache->flush();

        return $report;
    }

    /**
     * ICU answers an unknown region with the code itself; that is not a name.
     */
    private function arabicName(string $iso2): ?string
    {
        if (! extension_loaded('intl')) {
            return null;
        }

        $name = Locale::getDisplayRegion("-{$iso2}", 'ar');

        return $name === '' || $name === $iso2 ? null : $name;
    }

    /**
     * "970" → "+970". Some countries list several ("1-809 and 1-829"); the
     * leading code is the one they share.
     */
    private function dialCode(string $phone): ?string
    {
        return preg_match('/^\+?(\d{1,4})/', trim($phone), $match) ? '+'.$match[1] : null;
    }
}
