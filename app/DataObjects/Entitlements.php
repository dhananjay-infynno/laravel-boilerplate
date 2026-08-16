<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * What a user is currently allowed to do — `docs/00-MASTER-PLAN.md` §4.10.
 *
 * ONE object answers every entitlement question in the system, so there is no
 * second place where "can this user write?" is decided differently.
 */
final readonly class Entitlements
{
    public function __construct(
        public int $maxAccounts,
        public bool $canWrite,
        public bool $canExternalTransfer,
        public bool $canExport,
        public string $planCode,
        public SubscriptionStatus $status,
        public ?CarbonImmutable $trialEndsAt = null,
        public ?CarbonImmutable $currentPeriodEnd = null,
        public ?CarbonImmutable $graceEndsAt = null,
    ) {}

    /**
     * The fallback for a user with no subscription row at all.
     *
     * Read-only, but `canExport` stays TRUE — see below.
     */
    public static function none(): self
    {
        return new self(
            maxAccounts: 0,
            canWrite: false,
            canExternalTransfer: false,
            canExport: true,
            planCode: 'none',
            status: SubscriptionStatus::Expired,
        );
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    /** Whole days left in the trial, floored at 0. */
    public function trialDaysRemaining(): int
    {
        if ($this->trialEndsAt === null || $this->trialEndsAt->isPast()) {
            return 0;
        }

        return (int) ceil(CarbonImmutable::now()->diffInDays($this->trialEndsAt, false));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_accounts' => $this->maxAccounts,
            'can_write' => $this->canWrite,
            'can_external_transfer' => $this->canExternalTransfer,
            // Deliberately always true. Locking someone out of their own
            // financial records because they stopped paying is bad practice and
            // a legal problem under GDPR and India's DPDP Act. Do not
            // "optimise" this away.
            'can_export' => $this->canExport,
            'plan_code' => $this->planCode,
            'status' => $this->status->value,
            'trial_ends_at' => self::toIso($this->trialEndsAt),
            'current_period_end' => self::toIso($this->currentPeriodEnd),
            'grace_ends_at' => self::toIso($this->graceEndsAt),
            'days_remaining' => $this->trialDaysRemaining(),
        ];
    }

    private static function toIso(?CarbonImmutable $value): ?string
    {
        return $value?->toIso8601String();
    }
}
