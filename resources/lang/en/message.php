<?php

return [
    // Default envelope message when a controller does not supply one.
    'success' => 'Success.',

    'register_success' => 'Registration successful! Welcome aboard!',
    'login_success' => 'Login successful! Welcome back!',
    'user_profile_update' => 'Your profile updated successfully',
    'password_change_success' => 'Password changed successfully.',
    'inactive_user' => 'Account Inactive: Please contact administrator to reactivate your account.',
    'email_not_exist' => 'This email address is not registered in the system.',
    'forget_password_email_success' => 'Forgot Password OTP has been sent sent your email address.',
    'otp_verified_successfully' => 'OTP verified successfully. You can now proceed with password reset.',
    'logout_success' => 'Logout successfully.',
    'email_verified_successfully' => 'Email verified. Your 30-day free trial has started.',
    // Deliberately non-committal: the endpoint must not confirm whether the
    // address is registered.
    'otp_sent' => 'If that address is registered, a code has been sent to it.',
    'otp_expired' => 'Otp expired.',
    'invalid_otp' => 'Invalid OTP. Please try again.',
    'onesignal_data_success' => 'OneSignal player ID stored successfully.',
    'notification_read_success' => 'Notifications marked as read successfully.',
    'notification_unread_success' => 'Notifications marked as unread successfully.',
];
