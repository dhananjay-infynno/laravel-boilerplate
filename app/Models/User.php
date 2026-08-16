<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Observers\UserObserver;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Plank\Mediable\Mediable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Passport\Contracts\OAuthenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * @property UserStatus $status
 */
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable implements HasLocalePreference, OAuthenticatable
{
    /**
     * HasUuid: the users table carries a NOT NULL unique `uuid`. Without this
     * trait every registration dies with "Field 'uuid' doesn't have a default
     * value" — a bug that cost an afternoon the first time round.
     *
     * HasFactory: absent from the boilerplate's User, and User::factory() is
     * used throughout the test suite.
     */
    use BaseModel, HasApiTokens, HasFactory, HasRoles, HasUuid, Mediable, Notifiable, SoftDeletes;

    /**
     * Route-model binding stays on the primary key.
     *
     * HasUuid would otherwise switch this to `uuid`, silently breaking the
     * existing admin route `users/{user}/change-status`. The public API never
     * routes to a user by key — it uses /me — so there is nothing to gain and a
     * broken admin panel to lose.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'locale',
        'status',
        'password',
        'country_code',
        'mobile_no',
        'email_verified_at',
        'last_login_at',
        'created_at',
        // FinTrack
        'currency_code',
        'timezone',
        'phone',
        'phone_verified_at',
        'trial_ends_at',
        'current_session_id',
        'last_login_ip',
        'registration_source',
        'referred_by_user_id',
        'is_suspended',
        'suspended_reason',
        // Billing identity. Snapshot onto the invoice at issue time, so editing
        // these never rewrites a document already filed.
        'billing_name',
        'billing_address',
        'billing_city',
        'billing_postal_code',
        'state_code',
        'gstin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Credential material. Must never appear in a response or a log.
        'app_pin_hash',
        'current_session_id',
    ];

    protected $guard_name = 'api';

    /** Accessors and Mutators */
    protected $appends = ['name', 'display_status', 'display_mobile_no'];

    protected $relationship = [
        'user_device' => [
            'model' => UserDevice::class,
        ],
    ];

    public function userDevice()
    {
        return $this->hasOne(UserDevice::class);
    }

    /** Created by UserObserver on registration, so nothing downstream null-checks. */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The one non-terminal subscription, if any. */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->latestOfMany();
    }

    public function sequence(): HasOne
    {
        return $this->hasOne(UserSequence::class);
    }

    public function preferredLocale(): string
    {
        return $this->locale ?? config('app.locale');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'timestamp',
            'password' => 'hashed',
            'last_login_at' => 'timestamp',
            // FinTrack. NOTE: 'timestamp' yields an INT, not a Carbon — see the
            // open decision in docs/09-BUILD-STATUS.md §3.1. Anything reading
            // trial_ends_at must not assume a date object.
            'phone_verified_at' => 'timestamp',
            'trial_ends_at' => 'timestamp',
            'current_session_id' => 'string',
            'pin_enabled' => 'boolean',
            'biometric_enabled' => 'boolean',
            'is_suspended' => 'boolean',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
            'status' => UserStatus::class,
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->first_name . ' ' . $this->last_name,
        );
    }

    protected function displayStatus(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status->label(),
        );
    }

    protected function displayMobileNo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->country_code . ' ' . $this->mobile_no,
        );
    }
}
