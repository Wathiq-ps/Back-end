<?php

namespace App\Console\Commands\Locations;

use App\Services\Locations\Import\ImportReport;
use App\Services\Locations\Import\ImportSourceException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

trait PrintsImportReport
{
    /**
     * Runs the import and prints its report. A failed download — the public
     * Overpass instance in particular answers 429/502/504 when busy — or an
     * unreadable source file is a one-line error, not a stack trace. Nothing
     * was written: every importer reads its whole source before it writes,
     * or inside the transaction that the error rolls back.
     *
     * @param  Closure(): ImportReport  $import
     */
    private function runImport(Closure $import, string $what): int
    {
        try {
            $report = $import();
        } catch (RequestException|ConnectionException $exception) {
            $status = $exception instanceof RequestException ? " (HTTP {$exception->response->status()})" : '';
            $this->error("Fetching {$what} failed{$status}: ".strtok($exception->getMessage(), "\n"));
            $this->line('Nothing was written. Public servers rate-limit heavy queries: wait a few minutes and run it again.');

            return self::FAILURE;
        } catch (ImportSourceException $exception) {
            $this->error("Reading {$what} failed: {$exception->getMessage()}");
            $this->line('Nothing was written.');

            return self::FAILURE;
        }

        $this->table(['Created', 'Updated', 'Unchanged', 'Skipped'], [[
            $report->created, $report->updated, $report->unchanged, $report->skippedTotal(),
        ]]);

        foreach ($report->skipped as $reason => $count) {
            $this->line("  skipped {$count}: {$reason}");
        }

        $this->info("Done importing {$what}.");

        return self::SUCCESS;
    }
}
