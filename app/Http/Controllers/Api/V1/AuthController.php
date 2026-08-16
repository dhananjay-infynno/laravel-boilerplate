<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgetPassword as ForgetPasswordRequest;
use App\Http\Requests\Auth\Login as LoginRequest;
use App\Http\Requests\Auth\Register as RegisterRequest;
use App\Http\Requests\Auth\ResetPassword as ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyOtp;
use App\Services\AuthService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Auth
 */
#[Group('Auth', weight: 10)]
class AuthController extends Controller
{
    use ApiResponser;

    /**
     * Constructor injection, not `new AuthService`.
     *
     * The old form defeated the container entirely — the service could not be
     * given a test double, and its own dependencies had to be instantiated by
     * hand inside it.
     */
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Register.
     *
     * Sends a verification OTP. The 30-day trial does NOT start here — it
     * starts on verification.
     *
     * @unauthenticated
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $this->authService->register($request->validated());

        return $this->success($data, (string) __('message.register_success'), 201);
    }

    /**
     * Verify email.
     *
     * Verifies the OTP, starts the trial, and signs the user in.
     *
     * @unauthenticated
     */
    public function verifyEmail(VerifyOtp $request): JsonResponse
    {
        $data = $this->authService->verifyEmail($request->validated());

        return $this->success($data, (string) __('message.email_verified_successfully'));
    }

    /**
     * Resend an OTP.
     *
     * Always reports success — confirming whether an address is registered
     * would make this an account enumeration oracle.
     *
     * @unauthenticated
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'purpose' => ['nullable', 'in:email_verification,forgot_password'],
        ]);

        $this->authService->resendOtp($request->all());

        return $this->success(null, (string) __('message.otp_sent'));
    }

    /**
     * Log in.
     *
     * Revokes every other session — one live device per account.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $this->authService->login($request->validated());

        return $this->success($data, (string) __('message.login_success'));
    }

    /**
     * Verify a password-reset OTP.
     *
     * @unauthenticated
     */
    public function forgotPasswordOTPVerify(VerifyOtp $request): JsonResponse
    {
        $data = $this->authService->forgotPasswordOTPVerify($request->validated());

        return $this->success($data, (string) __('message.otp_verified_successfully'));
    }

    /**
     * Request a password reset.
     *
     * @unauthenticated
     */
    public function forgotPassword(ForgetPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->validated());

        return $this->success(null, (string) __('message.forget_password_email_success'));
    }

    /**
     * Reset the password.
     *
     * Also revokes every session — a reset must invalidate all devices.
     *
     * @unauthenticated
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated());

        return $this->success(null, (string) __('message.password_change_success'));
    }

    /**
     * Log out of this device.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->all());

        return $this->success(null, (string) __('message.logout_success'));
    }

    /**
     * Log out of every device.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return $this->success(null, (string) __('message.logout_success'));
    }
}
