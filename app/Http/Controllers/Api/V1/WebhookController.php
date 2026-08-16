<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\Billing\ProcessRazorpayWebhookJob;
use App\Models\PaymentEvent;
use App\Services\PaymentGatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gateway webhook ingest.
 *
 * Four rules, in order of importance:
 *
 *  1. VERIFY THE SIGNATURE FIRST. This route is unauthenticated and publicly
 *     reachable — anyone can POST "your subscription is active" at it. The HMAC
 *     is the ONLY thing standing between that and free subscriptions.
 *
 *  2. PERSIST THE RAW PAYLOAD BEFORE PROCESSING. If a handler throws, the event
 *     is still on disk and replayable. Processing first and storing after means
 *     a bug silently loses a payment.
 *
 *  3. RETURN 200 FAST. Razorpay times out in a few seconds and retries with
 *     backoff. Doing the work inline turns a slow query into a duplicate
 *     delivery storm. Parse, store, queue, return.
 *
 *  4. RETURN 200 FOR DUPLICATES TOO. A replay is a success from the gateway's
 *     point of view — the event is already recorded. Non-2xx makes it retry
 *     forever.
 *
 * This controller has NO auth middleware and NO `can.write`. That is deliberate:
 * the signature IS the authentication, and a past_due user's renewal webhook
 * must be processed precisely because they cannot write.
 */
final class WebhookController extends Controller
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function razorpay(Request $request): JsonResponse
    {
        $gateway = $this->gateways->driver('razorpay');

        if (! $gateway->verifyWebhookSignature($request)) {
            /*
             * Logged WITHOUT the body. A forged payload is attacker-controlled
             * data and putting it in the log invites log injection; the
             * ip/length are enough to spot a probe.
             *
             * 400, not 401 — there is no auth challenge to make.
             */
            Log::warning('Rejected Razorpay webhook: bad signature.', [
                'ip' => $request->ip(),
                'bytes' => strlen($request->getContent()),
            ]);

            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $payload = (array) $request->json()->all();
        $event = $gateway->parseWebhook($payload);

        try {
            $record = PaymentEvent::create([
                'gateway' => 'razorpay',
                'event_id' => $event->eventId,
                'event_type' => (string) ($payload['event'] ?? 'unknown'),
                'payload' => $payload,
                'signature' => (string) $request->header('X-Razorpay-Signature'),
                'status' => 'pending',
                'attempts' => 0,
            ]);
        } catch (QueryException $e) {
            // Unique violation on event_id = a retry of something already
            // stored. Exactly what the constraint is for. 200 so Razorpay
            // stops retrying.
            if ($this->isUniqueViolation($e)) {
                return response()->json(['status' => 'duplicate'], 200);
            }

            throw $e;
        }

        // afterCommit so the worker can never pick the row up before it exists.
        ProcessRazorpayWebhookJob::dispatch($record->id)->afterCommit();

        return response()->json(['status' => 'ok'], 200);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
