<?php

namespace App\Services\Locations\Import;

use Generator;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Reads the GeoNames dump files (https://download.geonames.org/export/dump/,
 * CC BY 4.0). Every reader takes an optional local path, so a file that was
 * downloaded once can be imported again offline; otherwise it is fetched
 * into a temporary file and removed afterwards.
 *
 * Files are streamed line by line: a large country's dump runs to hundreds
 * of megabytes unzipped.
 */
class GeoNamesClient
{
    /**
     * countryInfo.txt, one array per country, keyed by the header names
     * the importer uses.
     *
     * @return Generator<array{iso2: string, iso3: string, name: string, phone: string, geonameid: string}>
     */
    public function countries(?string $path = null): Generator
    {
        foreach ($this->rows($path ?? $this->download('countryInfo.txt'), $path === null) as $columns) {
            yield [
                'iso2' => $columns[0] ?? '',
                'iso3' => $columns[1] ?? '',
                'name' => $columns[4] ?? '',
                'phone' => $columns[12] ?? '',
                'geonameid' => $columns[16] ?? '',
            ];
        }
    }

    /**
     * {CC}.zip — every feature in the country.
     *
     * @return Generator<array{geonameid: string, name: string, latitude: string, longitude: string, feature_code: string, country_code: string, population: string}>
     */
    public function places(string $iso2, ?string $path = null): Generator
    {
        foreach ($this->rows($path ?? $this->download("{$iso2}.zip"), $path === null, "{$iso2}.txt") as $columns) {
            yield [
                'geonameid' => $columns[0] ?? '',
                'name' => $columns[1] ?? '',
                'latitude' => $columns[4] ?? '',
                'longitude' => $columns[5] ?? '',
                'feature_code' => $columns[7] ?? '',
                'country_code' => $columns[8] ?? '',
                'population' => $columns[14] ?? '',
            ];
        }
    }

    /**
     * alternatenames/{CC}.zip — names of the country's features by language.
     *
     * @return Generator<array{geonameid: string, language: string, name: string, preferred: bool, short: bool, colloquial: bool, historic: bool}>
     */
    public function alternateNames(string $iso2, ?string $path = null): Generator
    {
        foreach ($this->rows($path ?? $this->download("alternatenames/{$iso2}.zip"), $path === null, "{$iso2}.txt") as $columns) {
            yield [
                'geonameid' => $columns[1] ?? '',
                'language' => $columns[2] ?? '',
                'name' => $columns[3] ?? '',
                'preferred' => ($columns[4] ?? '') === '1',
                'short' => ($columns[5] ?? '') === '1',
                'colloquial' => ($columns[6] ?? '') === '1',
                'historic' => ($columns[7] ?? '') === '1',
            ];
        }
    }

    private function download(string $file): string
    {
        $target = tempnam(sys_get_temp_dir(), 'geonames-');

        Http::withUserAgent(config('locations.import.user_agent'))
            ->timeout(config('locations.import.timeout'))
            ->sink($target)
            ->get(rtrim(config('locations.import.geonames.base_url'), '/').'/'.$file)
            ->throw();

        return $target;
    }

    /**
     * Tab-separated rows of a .txt file, or of $entry inside a .zip. Comment
     * lines (#) and blank lines are skipped.
     *
     * @return Generator<list<string>>
     */
    private function rows(string $path, bool $temporary, ?string $entry = null): Generator
    {
        $handle = $this->open($path, $entry);

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                yield explode("\t", $line);
            }
        } finally {
            fclose($handle);

            if ($temporary) {
                @unlink($path);
            }
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, ?string $entry)
    {
        if (! is_file($path)) {
            throw new ImportSourceException("GeoNames file not found: {$path}");
        }

        if ($this->isZip($path)) {
            $zip = new ZipArchive;
            $found = $zip->open($path) === true && $entry !== null && $zip->locateName($entry) !== false;
            $zip->close();

            if (! $found) {
                throw new ImportSourceException("Expected {$entry} inside {$path}.");
            }

            $handle = fopen('zip://'.$path.'#'.$entry, 'r');
        } else {
            $handle = fopen($path, 'r');
        }

        if (! $handle) {
            throw new ImportSourceException("Could not read {$path}.");
        }

        return $handle;
    }

    private function isZip(string $path): bool
    {
        $handle = fopen($path, 'r');
        $magic = fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04";
    }
}
