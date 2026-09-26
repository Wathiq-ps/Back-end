<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\AiJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The AI service gives itself 60s and retries its callback for a few more,
 * but it runs jobs in-process: if it restarts mid-job, no callback ever
 * comes. Queued with a 3-minute delay when a job is accepted; a no-op if the
 * result arrived in time.
 */
class CheckAiJobTimeout implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $aiJobId) {}

    public function handle(AiJobService $ai): void
    {
        if ($job = AiJob::find($this->aiJobId)) {
            $ai->fail($job, 'no_callback', 'No callback from the AI service within 3 minutes.', 'timed_out');
        }
    }
}
