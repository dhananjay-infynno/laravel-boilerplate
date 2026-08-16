<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CountryController;
use App\Http\Controllers\Api\V1\EntryController;
use App\Http\Controllers\Api\V1\ExternalTransferController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SignedUrlController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\MarkNotificationsAsRead;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public / unauthenticated
|--------------------------------------------------------------------------
|
| Rate limits per `docs/02-API-SPEC.md` §12. Throttled by IP because the caller
| is unauthenticated — everything behind auth:api throttles by user id instead.
| IP-only limiting is blunt in India, where a lot of mobile traffic shares CGNAT
| addresses, so the limits are deliberately generous rather than tight.
*/
Route::controller(AuthController::class)->group(function (): void {
    Route::post('register', 'register')->middleware('throttle:3,60');
    Route::post('login', 'login')->middleware('throttle:5,15');
    Route::post('verify-email', 'verifyEmail')->middleware('throttle:10,15');
    Route::post('resend-otp', 'resendOtp')->middleware('throttle:3,60');
    Route::post('forgot-password', 'forgotPassword')->middleware('throttle:3,60');
    Route::post('forgot-password-otp-verify', 'forgotPasswordOTPVerify')->middleware('throttle:10,15');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:5,60');
});

Route::apiResource('languages', LanguageController::class)->only(['index', 'show']);
Route::get('countries', CountryController::class);
Route::post('generate-signed-url', SignedUrlController::class);

/*
 * Plans are public. The paywall renders before the user has decided anything,
 * and pricing is on the marketing site regardless.
 */
Route::get('plans', [SubscriptionController::class, 'plans'])->name('plans.index');

/*
 * Gateway webhook.
 *
 * NO auth middleware, and that is correct: the HMAC signature IS the
 * authentication. It is also deliberately outside `single.session` and
 * `can.write` — a past_due user's renewal webhook must be processed precisely
 * BECAUSE they cannot write.
 *
 * Throttle is high and exists only as a flood guard. Razorpay retries with
 * backoff, so a real burst is small; a 429 here would make it retry forever.
 */
Route::post('webhooks/razorpay', [WebhookController::class, 'razorpay'])
    ->middleware('throttle:300,1')
    ->name('webhooks.razorpay');

/*
|--------------------------------------------------------------------------
| Authenticated — boilerplate surface
|--------------------------------------------------------------------------
*/
Route::group(['middleware' => ['auth:api', MarkNotificationsAsRead::class]], function (): void {
    Route::controller(UserController::class)->group(function (): void {
        Route::get('me', 'me');
        Route::post('me', 'updateProfile');
        Route::post('change-password', 'changePassword');
        Route::post('locale', 'updateLocale');
    });

    Route::controller(NotificationController::class)->group(function (): void {
        Route::get('notifications', 'index');
        Route::get('notifications/unread-count', 'unreadCount');
        Route::post('notifications/read', 'readAllNotification');
        Route::post('notifications/unread', 'markAsUnread');
        Route::post('onesignal-player-id', 'setOnesignalData');
    });

    Route::delete('media/{media}', [MediaController::class, 'destroy']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('logout-all', [AuthController::class, 'logoutAll']);
});

/*
|--------------------------------------------------------------------------
| FinTrack — the ledger
|--------------------------------------------------------------------------
|
| `single.session` enforces one live device per account.
|
| READS sit outside `can.write`; WRITES sit inside it. That is the read-only
| lock for a lapsed subscription, expressed in one place rather than scattered
| through controllers: a user who stops paying keeps full visibility of their
| own data and can always export it, but cannot add to it.
*/
Route::middleware(['auth:api', 'single.session'])->group(function (): void {

    /* ---------------------------------------------------------------- Accounts */

    /*
     * Registered BEFORE the resource routes, or "lookup" and "reorder" would be
     * captured as an account uuid.
     *
     * The lookup is the one route that answers questions about OTHER people's
     * accounts, so it is the one an attacker would walk the whole number space
     * with — hence 10/min.
     */
    Route::get('accounts/lookup/{accountNumber}', [AccountController::class, 'lookup'])
        ->middleware('throttle:10,1')
        ->name('accounts.lookup');

    Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);

    Route::middleware('can.write')->group(function (): void {
        Route::post('accounts/reorder', [AccountController::class, 'reorder'])->name('accounts.reorder');
        Route::post('accounts/{account}/set-main', [AccountController::class, 'setMain'])->name('accounts.set-main');
        Route::apiResource('accounts', AccountController::class)->only(['store', 'update', 'destroy']);
    });

    /* ----------------------------------------------------------------- Entries */

    Route::apiResource('entries', EntryController::class)->only(['index', 'show']);

    Route::middleware('can.write')->group(function (): void {
        // `idempotent` is mandatory: the mobile app retries on flaky networks,
        // and without a key a retry posts the user's money twice.
        Route::post('entries', [EntryController::class, 'store'])
            ->middleware('idempotent')
            ->name('entries.store');

        Route::delete('entries/{entry}', [EntryController::class, 'destroy'])->name('entries.destroy');
    });

    /* ------------------------------------------------------ External transfers */

    Route::get('external-transfers/pending-count', [ExternalTransferController::class, 'pendingCount'])
        ->name('external-transfers.pending-count');

    Route::get('external-transfers', [ExternalTransferController::class, 'index'])
        ->name('external-transfers.index');

    Route::get('external-transfers/{externalTransfer}', [ExternalTransferController::class, 'show'])
        ->name('external-transfers.show');

    /*
     * accept / reject / cancel sit OUTSIDE can.write deliberately.
     *
     * A lapsed subscriber must still be able to respond to money someone sent
     * them — refusing would strand the SENDER's funds too, which is a worse
     * outcome than the revenue it would protect.
     */
    Route::post('external-transfers/{externalTransfer}/accept', [ExternalTransferController::class, 'accept'])
        ->name('external-transfers.accept');
    Route::post('external-transfers/{externalTransfer}/reject', [ExternalTransferController::class, 'reject'])
        ->name('external-transfers.reject');
    Route::post('external-transfers/{externalTransfer}/cancel', [ExternalTransferController::class, 'cancel'])
        ->name('external-transfers.cancel');

    Route::middleware('can.write')->group(function (): void {
        Route::post('external-transfers', [ExternalTransferController::class, 'store'])
            ->middleware(['idempotent', 'throttle:10,1'])
            ->name('external-transfers.store');
    });

    /* ----------------------------------------------------------------- Reports */

    // All reads — served from daily_account_summaries, never by scanning entries.
    Route::controller(ReportController::class)->prefix('reports')->name('reports.')->group(function (): void {
        Route::get('day', 'day')->name('day');
        Route::get('account', 'account')->name('account');
        Route::get('summary', 'summary')->name('summary');
        Route::get('chart', 'chart')->name('chart');
    });

    /* ---------------------------------------------- Settings, PIN, sessions */

    Route::get('settings', [SettingController::class, 'show'])->name('settings.show');
    Route::patch('settings', [SettingController::class, 'update'])->name('settings.update');

    Route::post('settings/pin', [SettingController::class, 'setPin'])->name('settings.pin.set');
    Route::delete('settings/pin', [SettingController::class, 'removePin'])->name('settings.pin.remove');

    // 5/min: a 4-digit code behind an unthrottled endpoint falls in minutes.
    // This limit is the security control, not the code length.
    Route::post('settings/pin/verify', [SettingController::class, 'verifyPin'])
        ->middleware('throttle:5,1')
        ->name('settings.pin.verify');

    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

    // Required by Google Play before the app can ship.
    Route::delete('me', [AccountDeletionController::class, 'destroy'])->name('me.destroy');
});

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| Authenticated but NOT behind `can.write`, and that is the whole point: an
| expired user has to be able to reach checkout. Putting the way out of the
| paywall behind the paywall is a dead end nobody notices until a customer
| emails about it.
|
| `single.session` is also skipped. Checkout frequently bounces through a bank
| app or a UPI app and comes back, and on some devices that is enough to look
| like a new session — killing the user's token mid-payment would be the worst
| possible moment to do it.
*/
Route::middleware(['auth:api'])->prefix('billing')->name('billing.')->group(function (): void {
    Route::get('subscription', [SubscriptionController::class, 'current'])->name('subscription');

    // 10/min: each call creates a real subscription object at the gateway, so
    // an unthrottled loop pollutes the Razorpay dashboard with dead records.
    Route::post('checkout', [SubscriptionController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('checkout');

    // Verifies a signature only. Grants nothing — see the controller.
    Route::post('verify', [SubscriptionController::class, 'verify'])
        ->middleware('throttle:20,1')
        ->name('verify');

    Route::post('change-plan', [SubscriptionController::class, 'changePlan'])
        ->middleware('throttle:5,1')
        ->name('change-plan');

    Route::post('cancel', [SubscriptionController::class, 'cancel'])->name('cancel');
    Route::post('resume', [SubscriptionController::class, 'resume'])->name('resume');

    Route::get('payments', [SubscriptionController::class, 'payments'])->name('payments');
    Route::get('invoices', [SubscriptionController::class, 'invoices'])->name('invoices');
    Route::get('invoices/{invoice}/download', [SubscriptionController::class, 'downloadInvoice'])
        ->name('invoices.download');
});
