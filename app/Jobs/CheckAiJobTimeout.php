<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\AiJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The AI runs jobs in-process: if it restarts mid-job, no callback ever comes.
 * Queued when a job is accepted, delayed by the AI's own deadline plus slack
 * (SendAiJob); a no-op if the result arrived in time.
 *
 * It fails the job — `failed` / no_callback, not `timed_out`, which is the
 * AI's own verdict that the job ran out of budget. A success that still
 * arrives later is applied (AiJobService::acceptsLate).
 */
class CheckAiJobTimeout implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $aiJobId, public int $afterSeconds = 180) {}

    public function handle(AiJobService $ai): void
    {
        if ($job = AiJob::find($this->aiJobId)) {
            $ai->fail($job, 'no_callback', "No callback from the AI service within {$this->afterSeconds}s.");
        }
    }
}
