<?php

declare(strict_types=1);

return [

    'no_subscription' => 'You do not have an active subscription.',
    'checkout_created' => 'Checkout ready.',
    'verified' => 'Payment received. Your subscription is being activated.',
    'verification_failed' => 'We could not verify this payment. If money was debited it will be refunded automatically within 5-7 working days.',
    'plan_changed' => 'Your plan has been updated.',
    'plan_unavailable' => 'That plan is not available.',
    'cancelled' => 'Your subscription has been cancelled.',
    'nothing_to_resume' => 'There is no cancelled subscription to resume.',
    'resumed' => 'Your subscription has been resumed.',
    'invoice_not_found' => 'Invoice not found.',

    /*
     * Dunning emails.
     *
     * Written to be reassuring rather than threatening. A failed UPI mandate in
     * this market is usually a bank issue, not a customer refusing to pay, and
     * an accusatory first email costs more in churn than the invoice is worth.
     */
    'dunning' => [
        'action' => 'Update payment method',
        'reason' => 'Reason given by the bank: :reason',
        'first' => [
            'subject' => 'We could not process your FinTrack payment',
            'line' => 'Your payment did not go through. This is usually a temporary bank issue. We will try again automatically — your data and your access are untouched.',
        ],
        'second' => [
            'subject' => 'Reminder: FinTrack payment still pending',
            'line' => 'We have not been able to collect your subscription payment yet. Your account still works normally, but please check your UPI mandate or update your payment method.',
        ],
        'final' => [
            'subject' => 'Action needed: FinTrack access pauses in :days days',
            'line' => 'We still could not collect your payment. To avoid your account being paused on :date, please update your payment method.',
        ],
        'suspended' => [
            'subject' => 'Your FinTrack account has been paused',
            // The reassurance matters: this is their financial record and the
            // fear of losing it is what generates angry support tickets.
            'line' => 'Your account is paused because we could not collect payment. Nothing has been deleted — every entry is safe, and everything comes back the moment payment succeeds.',
        ],
    ],

    'receipt' => [
        'subject' => 'Your FinTrack receipt :number',
        'line' => 'Thank you. Your payment has been received and your invoice is attached.',
        'action' => 'View invoices',
    ],

    'trial' => [
        'subject' => 'Your FinTrack trial ends in :days days',
        'line' => 'Your free trial ends on :date. Pick a plan to keep adding entries after that.',
        'reassurance' => 'Whatever you decide, your data stays. Even after the trial ends you can still view, search and export everything you have recorded — you just will not be able to add new entries until you subscribe.',
        'action' => 'Choose a plan',
    ],

    'mandate_revoked' => [
        'subject' => 'Your FinTrack auto-pay was cancelled',
        'line' => 'The auto-pay mandate for FinTrack was cancelled from your bank or UPI app, so we can no longer collect payment automatically. Your access continues until :date.',
        'action' => 'Set up payment again',
    ],

];
