<?php

namespace App\Console\Commands\Locations;

use App\Models\City;
use App\Models\Country;
use App\Services\Locations\Import\AreaImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportAreas extends Command
{
    use PrintsImportReport;

    protected $signature = 'locations:import-areas
        {country : ISO 3166-1 alpha-2 code, e.g. PS}
        {--city= : Only query around this city (its id) — for large countries, one city at a time}
        {--max-distance= : Attach an area only to a city within this many km (default from config)}';

    protected $description = 'Import or update districts and neighbourhoods from OpenStreetMap, attached to the nearest city';

    public function handle(AreaImporter $importer): int
    {
        $iso2 = strtoupper((string) $this->argument('country'));
        $country = Country::where('iso2', $iso2)->first();

        if (! $country) {
            $this->error("No country {$iso2} in the database. Run locations:import-countries first.");

            return self::FAILURE;
        }

        $city = null;

        if ($cityId = $this->option('city')) {
            $city = Str::isUuid($cityId) ? City::where('country_id', $country->id)->find($cityId) : null;

            if (! $city) {
                $this->error("No city {$cityId} in {$iso2}.");

                return self::FAILURE;
            }
        }

        $maxKm = $this->option('max-distance') !== null ? (float) $this->option('max-distance') : null;

        return $this->runImport(
            fn () => $importer->import($country, $city, $maxKm),
            "areas of {$iso2}".($city ? " around {$city->name}" : ''),
        );
    }
}
