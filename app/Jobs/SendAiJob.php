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

    /** Queue and network time on top of the AI's own deadline. */
    public const CALLBACK_SLACK_SECONDS = 60;

    /** When the AI's 202 doesn't say (an older release). */
    public const FALLBACK_DEADLINE_SECONDS = 180;

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
            // The AI states how long it may still call back: its job budget
            // plus every callback attempt (respond_within_seconds). The no-
            // callback check follows that number, not a copy of it kept here.
            $within = $response->json('respond_within_seconds');
            $deadline = is_int($within) ? $within + self::CALLBACK_SLACK_SECONDS : self::FALLBACK_DEADLINE_SECONDS;

            // One transaction: the database queue's row commits with the
            // status, so a worker dying in between can't leave a running job
            // that nothing will ever time out.
            DB::transaction(function () use ($job, $deadline) {
                // Conditional again: a fast failure can call back before this line.
                AiJob::whereKey($job->id)->where('status', 'dispatched')->update(['status' => 'running']);
                CheckAiJobTimeout::dispatch($job->id, $deadline)->delay(now()->addSeconds($deadline));
            });

            return;
        }

        if ($response->status() === 422) {
            // The AI read the request and refused it; resending won't change that.
            $ai->fail($job, 'rejected_by_ai', $response->body());

            return;
        }

        // 5xx, and any other 4xx — a proxy's 404, 413 or 429 is not the AI
        // refusing the job: let the queue retry with backoff.
        $response->throw();
    }

    public function failed(?Throwable $e): void
    {
        if ($job = AiJob::find($this->aiJobId)) {
            app(AiJobService::class)->fail($job, 'dispatch_failed', (string) $e?->getMessage());
        }
    }
}
