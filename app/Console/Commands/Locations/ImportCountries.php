<?php

namespace App\Console\Commands\Locations;

use App\Services\Locations\Import\CountryImporter;
use Illuminate\Console\Command;

class ImportCountries extends Command
{
    use PrintsImportReport;

    protected $signature = 'locations:import-countries
        {--file= : A local copy of GeoNames countryInfo.txt instead of downloading it}';

    protected $description = 'Import or update countries from GeoNames (Arabic names from ICU); existing names are never overwritten';

    public function handle(CountryImporter $importer): int
    {
        return $this->runImport(fn () => $importer->import($this->option('file')), 'countries');
    }
}
