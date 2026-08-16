<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | `key` is publishable and ships to the mobile app. `secret` and
    | `webhook_secret` NEVER leave the server — keep them in the host's secret
    | manager, never in the repo.
    |
    | Live keys belong in production only. A staging environment holding live
    | keys is how a test run charges a real card.
    |
    */

    'key' => env('RAZORPAY_KEY'),
    'secret' => env('RAZORPAY_SECRET'),
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Mandate
    |--------------------------------------------------------------------------
    |
    | The ceiling the customer authorises, NOT the plan price.
    |
    | Set at Rs 2,000 deliberately: it covers every current tier and any
    | plausible future one, so an upgrade never forces the user through
    | re-authorisation. They are only ever debited the actual plan amount.
    |
    | UPI Autopay caps a single auto-debit at Rs 15,000 without additional
    | authentication, so this is comfortably inside the limit.
    |
    */

    'mandate_max_amount' => env('RAZORPAY_MANDATE_MAX', '2000.00'),

    /*
    |--------------------------------------------------------------------------
    | Billing behaviour
    |--------------------------------------------------------------------------
    |
    | `grace_days` is how long a past_due subscription keeps full access.
    |
    | Seven days is deliberate. In this market a mandate failing for a day is
    | routine — bank downtime, insufficient balance at month end, an app update
    | — and locking out a paying customer over it costs far more in churn and
    | support than the charge is worth.
    |
    */

    'grace_days' => (int) env('RAZORPAY_GRACE_DAYS', 7),

    /*
    | Total billing cycles. Razorpay requires a finite count; 120 months is ten
    | years, long enough to be effectively perpetual.
    */
    'total_count' => [
        'month' => (int) env('RAZORPAY_TOTAL_COUNT_MONTHLY', 120),
        'year' => (int) env('RAZORPAY_TOTAL_COUNT_YEARLY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | GST
    |--------------------------------------------------------------------------
    |
    | 18% on SaaS sold in India, and prices are INCLUSIVE — a consumer product
    | advertising Rs 99 and charging Rs 116.82 loses conversions and generates
    | refund requests.
    |
    | So Rs 99 = Rs 83.90 base + Rs 15.10 GST. Invoices must show the split,
    | your GSTIN, the customer's state (CGST+SGST same state, otherwise IGST)
    | and the SAC code. Have a CA review the first invoice.
    |
    */

    'gst' => [
        'enabled' => (bool) env('RAZORPAY_GST_ENABLED', true),
        'rate' => (float) env('RAZORPAY_GST_RATE', 18.0),
        'inclusive' => (bool) env('RAZORPAY_GST_INCLUSIVE', true),
        'gstin' => env('COMPANY_GSTIN'),
        'state_code' => env('COMPANY_STATE_CODE'),
        // 997331 — licensing services for the right to use software. Confirm
        // with your CA before the first invoice goes out.
        'sac_code' => env('RAZORPAY_SAC_CODE', '997331'),
    ],

];
