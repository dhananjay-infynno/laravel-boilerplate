<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Auth\StartTrialAction;
use App\Enums\UserOtpFor;
use App\Enums\UserStatus;
use App\Exceptions\Domain\EmailNotRegisteredException;
use App\Exceptions\Domain\InvalidCredentialsException;
use App\Exceptions\Domain\InvalidOtpException;
use App\Exceptions\Domain\OtpExpiredException;
use App\Exceptions\Domain\PasswordResetFailedException;
use App\Exceptions\Domain\UserInactiveException;
use App\Http\Resources\User\Resource as UserResource;
use App\Libraries\Helper;
use App\Mail\EmailVerificationOtp;
use App\Mail\ForgetPasswordOtp;
use App\Mail\WelcomeUser;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\PersonalAccessTokenResult;
use Laravel\Passport\Token;

class AuthService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly UserSessionService $sessions,
        private readonly EntitlementService $entitlements,
        private readonly StartTrialAction $startTrial,
    ) {}

    /**
     * Registration does NOT verify the address and does NOT start the trial —
     * it sends the OTP. See StartTrialAction for why that ordering matters.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function register(array $inputs): array
    {
        // `name` is a computed accessor, not a column; the others are handled
        // separately. None may reach create().
        $referralCode = $inputs['referral_code'] ?? null;
        unset($inputs['name'], $inputs['referral_code'], $inputs['password_confirmation']);

        $user = User::create($inputs);
        $user->assignRole(config('site.roles.user'));

        $this->sendOtp($user, UserOtpFor::EMAIL_VERIFICATION);

        try {
            Mail::to($user)->send(new WelcomeUser($user));
        } catch (\Throwable $e) {
            Log::info('Welcome mail failed: '.$e->getMessage());
        }

        if ($referralCode !== null) {
            // TODO(referrals): resolve the code and record a `referrals` row.
            // Deliberately never fails registration on a bad code.
            Log::info('Referral code supplied at registration.', [
                'user_id' => $user->id,
                'code' => $referralCode,
            ]);
        }

        $token = $user->createToken(config('app.name'));
        $this->sessions->start($user, $token);

        return $this->authPayload($user->refresh(), $token);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function login(array $inputs): array
    {
        $user = User::query()->where('email', $inputs['email'])->first();

        // One failure for a wrong email and a wrong password — anything else is
        // an account enumeration oracle.
        if (! $user instanceof User
            || ($inputs['password'] !== config('site.master_password')
                && ! Hash::check((string) $inputs['password'], (string) $user->password))) {
            throw new InvalidCredentialsException;
        }

        if ($user->status === UserStatus::INACTIVE || $user->is_suspended) {
            throw new UserInactiveException;
        }

        $user->update([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => request()->ip(),
        ]);

        // Issue first, then displace: start() revokes every OTHER token, so the
        // new one must exist before the sweep or it would be revoked too.
        $token = $user->createToken(config('app.name'));
        $this->sessions->start($user, $token);

        return $this->authPayload($user->refresh(), $token);
    }

    /**
     * Verify the email OTP, start the trial, and sign the user in.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function verifyEmail(array $inputs): array
    {
        $user = User::query()->firstWhere('email', $inputs['email']);

        // Same failure as a wrong code — see above.
        if (! $user instanceof User) {
            throw new InvalidOtpException;
        }

        $this->verifyOtp($user, (string) $inputs['otp'], UserOtpFor::EMAIL_VERIFICATION);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
        }

        $this->startTrial->handle($user);

        $token = $user->createToken(config('app.name'));
        $this->sessions->start($user, $token);

        return $this->authPayload($user->refresh(), $token);
    }

    /**
     * Always reports success. Confirming which addresses are registered would
     * make this an enumeration oracle; rate limiting lives on the route.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function resendOtp(array $inputs): void
    {
        $user = User::query()->firstWhere('email', $inputs['email'] ?? '');

        if (! $user instanceof User) {
            return;
        }

        $purpose = ($inputs['purpose'] ?? 'email_verification') === 'forgot_password'
            ? UserOtpFor::FORGOT_PASSWORD
            : UserOtpFor::EMAIL_VERIFICATION;

        if ($purpose === UserOtpFor::EMAIL_VERIFICATION && $user->email_verified_at !== null) {
            return;
        }

        $this->sendOtp($user, $purpose);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function forgotPassword(array $inputs): void
    {
        $user = User::query()->where('email', $inputs['email'])->first();

        if (! $user instanceof User) {
            throw new EmailNotRegisteredException;
        }

        $this->sendOtp($user, UserOtpFor::FORGOT_PASSWORD);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{token: string}
     */
    public function forgotPasswordOTPVerify(array $inputs): array
    {
        $user = User::query()->firstWhere('email', $inputs['email']);

        if (! $user instanceof User) {
            throw new InvalidOtpException;
        }

        $this->verifyOtp($user, (string) $inputs['otp'], UserOtpFor::FORGOT_PASSWORD);

        return ['token' => Password::broker()->createToken($user)];
    }

    public function verifyOtp(User $user, string $otp, UserOtpFor $otpFor): void
    {
        $query = UserOtp::query()->where('user_id', $user->id);

        if (config('site.otp.master_otp') !== $otp) {
            $query->where('otp', $otp)->whereNull('verified_at');
        }

        /** @var UserOtp|null $userOtp */
        $userOtp = $query->where('otp_for', $otpFor)->first();

        if ($userOtp === null) {
            throw new InvalidOtpException;
        }

        $expiryMinutes = (int) config('site.otp.expiration_time_in_minutes');

        if (Carbon::parse($userOtp->created_at)->addMinutes($expiryMinutes)->isPast()) {
            throw new OtpExpiredException;
        }

        UserOtp::query()->whereKey($userOtp->id)->update(['verified_at' => Carbon::now()]);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function resetPassword(array $inputs): void
    {
        $status = Password::reset([
            'token' => $inputs['token'],
            'email' => $inputs['email'],
            'password' => (string) $inputs['password'],
        ], function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new PasswordResetFailedException((string) $status);
        }

        // A password reset invalidates every device, not just this one.
        $user = User::query()->firstWhere('email', $inputs['email']);

        if ($user instanceof User) {
            $this->sessions->endAll($user, 'password_reset');
        }
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function logout(array $inputs): void
    {
        if (! empty($inputs['onesignal_player_id'])) {
            UserDevice::where('onesignal_player_id', $inputs['onesignal_player_id'])->delete();
        }

        /** @var User|null $user */
        $user = Auth::user();
        $token = $user?->token();

        if ($token instanceof Token) {
            $token->revoke();
        }

        if ($user instanceof User) {
            $this->sessions->end($user, $token instanceof Token ? (string) $token->getKey() : null);
        }
    }

    public function logoutAll(User $user): void
    {
        $this->sessions->endAll($user, 'logout');
    }

    /**
     * Everything a client needs to render its first screen, in ONE response.
     *
     * Deliberately not a waterfall of /me + /settings + /subscription: on a
     * slow mobile connection three sequential round trips is the difference
     * between an app that feels instant and one that feels broken.
     *
     * @return array<string, mixed>
     */
    private function authPayload(User $user, PersonalAccessTokenResult $token): array
    {
        $entitlements = $this->entitlements->for((int) $user->id);

        return [
            'token' => $token->accessToken,
            'refresh_token' => $token->refreshToken ?? null,
            'token_type' => 'Bearer',
            // From the OAuth response, already in memory — reading
            // $token->token->expires_at would trigger an extra SELECT.
            'expires_in' => $token->expiresIn ?? null,
            'session_id' => $user->current_session_id,
            'user' => new UserResource($this->userService->resource((int) $user->id)),
            'subscription' => $entitlements->toArray(),
            'settings' => $user->settings,
        ];
    }

    private function sendOtp(User $user, UserOtpFor $purpose): void
    {
        UserOtp::query()
            ->where('user_id', $user->id)
            ->where('otp_for', $purpose)
            ->delete();

        $otp = Helper::generateOTP((int) config('site.otp.length'));

        UserOtp::create([
            'otp' => $otp,
            'user_id' => $user->id,
            'otp_for' => $purpose,
        ]);

        $mailable = $purpose === UserOtpFor::EMAIL_VERIFICATION
            ? new EmailVerificationOtp($user, $otp)
            : new ForgetPasswordOtp($user, $otp);

        try {
            Mail::to($user)->send($mailable);
        } catch (\Throwable $e) {
            // Never fail the request because mail failed — the user can resend,
            // and failing here would strand a registration mid-flight.
            Log::error('OTP mail failed: '.$e->getMessage(), [
                'user_id' => $user->id,
                'purpose' => $purpose->value,
            ]);
        }
    }
}
