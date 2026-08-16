<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable error codes — `docs/02-API-SPEC.md` §0.
 *
 * THIS is the client contract, not the message. Messages are translated and
 * will change; every client switches on the code. A client that string-matches
 * `message` breaks the first time someone edits a lang file.
 */
enum ErrorCode: string
{
    /* Validation and auth */
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case SessionRevoked = 'SESSION_REVOKED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case UserInactive = 'USER_INACTIVE';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case InvalidOtp = 'INVALID_OTP';
    case OtpExpired = 'OTP_EXPIRED';
    case EmailNotRegistered = 'EMAIL_NOT_REGISTERED';
    case PasswordResetFailed = 'PASSWORD_RESET_FAILED';

    /* Entitlements — all 402, all drive the paywall */
    case SubscriptionRequired = 'SUBSCRIPTION_REQUIRED';
    case AccountLimitReached = 'ACCOUNT_LIMIT_REACHED';
    case FeatureNotInPlan = 'FEATURE_NOT_IN_PLAN';
    case DowngradeBlocked = 'DOWNGRADE_BLOCKED';

    /* Ledger */
    case InsufficientBalance = 'INSUFFICIENT_BALANCE';
    case AccountInactive = 'ACCOUNT_INACTIVE';
    case AccountHasBalance = 'ACCOUNT_HAS_BALANCE';
    case CannotDeleteMainAccount = 'CANNOT_DELETE_MAIN_ACCOUNT';
    case AccountRecalculating = 'ACCOUNT_RECALCULATING';
    case EntryImmutable = 'ENTRY_IMMUTABLE';
    case TransferNotPending = 'TRANSFER_NOT_PENDING';
    case ExternalTransfersDisabled = 'EXTERNAL_TRANSFERS_DISABLED';

    /* Requests */
    case DuplicateRequest = 'DUPLICATE_REQUEST';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case RateLimited = 'RATE_LIMITED';
    case AppUpdateRequired = 'APP_UPDATE_REQUIRED';
    case MaintenanceMode = 'MAINTENANCE_MODE';
    case ServerError = 'SERVER_ERROR';

    /** The lang key carrying the human-facing message. */
    public function messageKey(): string
    {
        return 'errors.'.strtolower(str_replace('_', '_', $this->value));
    }

    /**
     * The HTTP status this code is served with by default.
     *
     * Individual exceptions may override, but keeping the mapping here means
     * the clients' `402 -> paywall` rule holds no matter which exception fired.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::InvalidOtp,
            self::OtpExpired,
            self::EmailNotRegistered,
            self::PasswordResetFailed,
            self::InsufficientBalance,
            self::AccountInactive,
            self::AccountHasBalance,
            self::CannotDeleteMainAccount,
            self::EntryImmutable,
            self::ExternalTransfersDisabled => 422,

            self::Unauthenticated,
            self::InvalidCredentials,
            self::SessionRevoked => 401,

            self::EmailNotVerified,
            self::AccountSuspended,
            self::UserInactive,
            self::Forbidden => 403,

            self::NotFound => 404,

            self::SubscriptionRequired,
            self::AccountLimitReached,
            self::FeatureNotInPlan,
            self::DowngradeBlocked => 402,

            self::AccountRecalculating,
            self::TransferNotPending,
            self::DuplicateRequest => 409,

            self::IdempotencyKeyRequired => 428,
            self::RateLimited => 429,
            self::AppUpdateRequired => 426,
            self::MaintenanceMode => 503,
            self::ServerError => 500,
        };
    }
}
