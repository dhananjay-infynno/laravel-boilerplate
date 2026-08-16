<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The canonical internal event set — `docs/03-BILLING.md` §11.
 *
 * Razorpay's vocabulary is translated into this ONCE, at the edge, in
 * RazorpayGateway::parseWebhook(). SubscriptionService handles only these, so
 * adding a second provider means writing one more translator rather than a
 * second set of handlers.
 */
enum WebhookEventType: string
{
    /** Mandate registered and first charge succeeded. */
    case SubscriptionActivated = 'subscription.activated';

    /** A renewal charge succeeded — extend the period. */
    case SubscriptionRenewed = 'subscription.renewed';

    /** A charge failed — start dunning and the grace clock. */
    case SubscriptionPaymentFailed = 'subscription.payment_failed';

    case SubscriptionCancelled = 'subscription.cancelled';

    case SubscriptionPaused = 'subscription.paused';

    case SubscriptionResumed = 'subscription.resumed';

    /** Fixed-cycle subscription finished, or retries exhausted. */
    case SubscriptionExpired = 'subscription.expired';

    /**
     * The user killed the mandate at their bank or in their UPI app.
     *
     * The one everybody forgets. With UPI Autopay a customer can cancel
     * WITHOUT ever opening this product — miss this and they stay `active`
     * forever while paying nothing.
     */
    case MandateRevoked = 'mandate.revoked';

    case PaymentCaptured = 'payment.captured';

    case PaymentFailed = 'payment.failed';

    case PaymentRefunded = 'payment.refunded';

    /** Received, recorded, no handler. Never an error. */
    case Unknown = 'unknown';
}
