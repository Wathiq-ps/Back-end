<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\AiJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs one ai_jobs row to the AI service. The AI answers 202 at once and
 * sends the result later to /api/v1/ai/callback; nothing here waits for it.
 */
class SendAiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public string $aiJobId) {}

    public function handle(AiJobService $ai): void
    {
        // Conditional, so a retry never reopens a job a callback already ended.
        $claimed = AiJob::whereKey($this->aiJobId)->whereIn('status', ['queued', 'dispatched'])->update([
            'status' => 'dispatched',
            'dispatched_at' => now(),
            'attempts' => DB::raw('attempts + 1'),
        ]);

        if (! $claimed) {
            return;
        }

        $job = AiJob::findOrFail($this->aiJobId);
        $response = Http::timeout(10)->post(rtrim((string) config('services.ai.url'), '/').'/v1/jobs', $ai->wirePayload($job));

        if ($response->status() === 202) {
            // Conditional again: a fast failure can call back before this line.
            AiJob::whereKey($job->id)->where('status', 'dispatched')->update(['status' => 'running']);
            CheckAiJobTimeout::dispatch($job->id)->delay(now()->addMinutes(3));

            return;
        }

        if ($response->clientError()) {
            // The AI read the request and refused it; resending won't change that.
            $ai->fail($job, 'rejected_by_ai', $response->body());

            return;
        }

        $response->throw(); // 5xx: let the queue retry with backoff
    }

    public function failed(?Throwable $e): void
    {
        if ($job = AiJob::find($this->aiJobId)) {
            app(AiJobService::class)->fail($job, 'dispatch_failed', (string) $e?->getMessage());
        }
    }
}
