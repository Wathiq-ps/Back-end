<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiJob;
use App\Services\AiJobService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where the AI service delivers job results (Wathiq-ps/Ai openapi.yaml,
 * /api/v1/ai/callback). No JWT: the HMAC signature is the authentication.
 *
 * The AI retries a delivery on a network error or 5xx, so the same result can
 * arrive more than once. Applying it is idempotent: the job row is locked and
 * a job that already finished is left alone.
 */
class AiCallbackController extends Controller
{
    public function __invoke(Request $request, AiJobService $ai): Response
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Wathiq-Signature');
        $this->verifySignature($signature, $raw);

        $data = json_decode($raw, true);
        abort_unless(
            is_array($data) && Str::isUuid($data['job_id'] ?? null)
                && in_array($data['status'] ?? null, ['succeeded', 'failed', 'timed_out'], true),
            422,
            'Malformed callback.',
        );

        DB::transaction(function () use ($data, $raw, $signature, $ai) {
            // webhook_signature_key accepts a signature once: an exact replay
            // of a captured request inserts nothing and changes nothing.
            $fresh = DB::table('ops.webhook_deliveries')->insertOrIgnore([
                'direction' => 'inbound',
                'source' => 'ai_service',
                'event_type' => $data['kind'] ?? null,
                'signature_valid' => true,
                'signature' => $signature,
                'request_id' => $data['job_id'],
                'http_status' => 204,
                'payload' => $raw,
            ]);

            if (! $fresh) {
                return;
            }

            $job = AiJob::whereKey($data['job_id'])->lockForUpdate()->first();
            abort_unless($job, 404, 'Unknown job.');

            if (in_array($job->status, AiJob::IN_FLIGHT, true)) {
                $ai->apply($job, $data);
            }
        });

        return response()->noContent();
    }

    /**
     * X-Wathiq-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>.
     * Checked against the raw bytes — decoding and re-encoding the JSON would
     * change them.
     */
    private function verifySignature(string $header, string $raw): void
    {
        $secret = (string) config('services.ai.webhook_secret');
        abort_if($secret === '', 500, 'AI webhook secret is not configured.');

        abort_unless(preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m) === 1, 401, 'Invalid signature.');
        abort_if(abs(time() - (int) $m[1]) > 300, 401, 'Stale signature.');
        abort_unless(hash_equals(hash_hmac('sha256', $m[1].'.'.$raw, $secret), $m[2]), 401, 'Invalid signature.');
    }
}
