<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Error messages
|--------------------------------------------------------------------------
|
| One entry per App\Enums\ErrorCode. These strings are for humans and WILL
| change — clients switch on `error_code`, never on the message.
|
| Keep them free of internal detail: an error message is returned to whoever
| triggered it, including someone probing the API. Note in particular that
| `invalid_credentials`, `invalid_otp` and `external_transfers_disabled` are
| deliberately vague — each covers several distinct causes that must stay
| indistinguishable from outside.
|
*/

return [

    /* Validation and auth */
    'validation_failed' => 'The information provided is not valid.',
    'unauthenticated' => 'Please sign in to continue.',
    'invalid_credentials' => 'The email or password is incorrect.',
    'session_revoked' => 'You signed in on another device, so this one was signed out.',
    'email_not_verified' => 'Please verify your email address to continue.',
    'account_suspended' => 'This account has been suspended. Please contact support.',
    'user_inactive' => 'This account is not active. Please contact support.',
    'forbidden' => 'You do not have access to this.',
    'not_found' => 'Not found.',
    'invalid_otp' => 'That code is not correct. Please check and try again.',
    'otp_expired' => 'That code has expired. Request a new one.',
    'email_not_registered' => 'No account was found for that email address.',
    'password_reset_failed' => 'That reset link is no longer valid. Please request a new one.',

    /* Entitlements */
    'subscription_required' => 'Your free trial has ended. Subscribe to continue adding entries — your data is safe and you can still view and export it.',
    'account_limit_reached' => 'You have reached your limit of :limit accounts. Upgrade your plan to add more.',
    'feature_not_in_plan' => 'This feature is not included in your current plan.',
    'downgrade_blocked' => 'Deactivate :count more account(s) before switching to this plan.',

    /* Ledger */
    'insufficient_balance' => 'This account does not have enough balance for that.',
    'account_inactive' => 'That account is inactive and cannot be used for entries.',
    'account_has_balance' => 'This account still holds a balance. Move it out before deleting.',
    'cannot_delete_main_account' => 'Your main account cannot be deleted. Make another account the main one first.',
    'account_recalculating' => 'This account is being recalculated. Please try again in a moment.',
    'entry_immutable' => 'The :field of a recorded entry cannot be changed. Delete it and add a new one.',
    'transfer_not_pending' => 'This transfer request is no longer pending.',
    'external_transfers_disabled' => 'That account cannot receive transfers.',

    /* Requests */
    'duplicate_request' => 'This request was already sent with different details.',
    'idempotency_key_required' => 'This request is missing its idempotency key.',
    'rate_limited' => 'Too many attempts. Please wait a moment and try again.',
    'app_update_required' => 'Please update the app to continue.',
    'maintenance_mode' => 'We are carrying out maintenance. Please try again shortly.',
    'server_error' => 'Something went wrong at our end. Please try again.',

];
