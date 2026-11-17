<?php

namespace App\Console\Commands\Locations;

use App\Models\Country;
use App\Services\Locations\Import\CityImporter;
use Illuminate\Console\Command;

class ImportCities extends Command
{
    use PrintsImportReport;

    protected $signature = 'locations:import-cities
        {country : ISO 3166-1 alpha-2 code of an imported country, e.g. PS}
        {--min-population=0 : Skip places with a smaller population (GeoNames records 0 when unknown)}
        {--feature-codes= : Comma-separated GeoNames feature codes, replacing config locations.import.geonames.feature_codes}
        {--file= : A local copy of the country dump (XX.zip or XX.txt)}
        {--alternate-names-file= : A local copy of alternatenames/XX.zip or its .txt}';

    protected $description = 'Import or update one country\'s cities from GeoNames';

    public function handle(CityImporter $importer): int
    {
        $iso2 = strtoupper((string) $this->argument('country'));
        $country = Country::where('iso2', $iso2)->first();

        if (! $country) {
            $this->error("No country {$iso2} in the database. Run locations:import-countries first.");

            return self::FAILURE;
        }

        $codes = $this->option('feature-codes')
            ? array_values(array_filter(array_map(fn ($code) => strtoupper(trim($code)), explode(',', $this->option('feature-codes')))))
            : config('locations.import.geonames.feature_codes');

        return $this->runImport(fn () => $importer->import(
            $country,
            $codes,
            max(0, (int) $this->option('min-population')),
            $this->option('file'),
            $this->option('alternate-names-file'),
        ), "cities of {$iso2}");
    }
}
