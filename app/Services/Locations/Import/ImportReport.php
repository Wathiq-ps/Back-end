<?php

namespace App\Services\Locations\Import;

/**
 * What one import run did, for the command to print. Skips are counted by
 * reason so a run that quietly dropped most of its input is visible as such.
 */
class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    /** @var array<string, int> */
    public array $skipped = [];

    public function skip(string $reason, int $count = 1): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + $count;
    }

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }
}
