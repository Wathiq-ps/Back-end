<?php

namespace App\Console\Commands\Locations;

use App\Models\City;
use App\Services\Locations\Import\StreetImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportStreets extends Command
{
    use PrintsImportReport;

    protected $signature = 'locations:import-streets
        {city : Id of the city whose streets to import (one city per run)}
        {--radius= : Fetch streets within this many metres of the city (default from config)}
        {--max-distance= : Attach a street only to an area within this many km (default from config)}';

    protected $description = 'Import or update one city\'s named streets from OpenStreetMap, attached to the nearest area';

    public function handle(StreetImporter $importer): int
    {
        $cityId = (string) $this->argument('city');
        $city = Str::isUuid($cityId) ? City::find($cityId) : null;

        if (! $city) {
            $this->error("No city {$cityId}.");

            return self::FAILURE;
        }

        return $this->runImport(fn () => $importer->import(
            $city,
            $this->option('radius') !== null ? (int) $this->option('radius') : null,
            $this->option('max-distance') !== null ? (float) $this->option('max-distance') : null,
        ), "streets of {$city->name}");
    }
}
