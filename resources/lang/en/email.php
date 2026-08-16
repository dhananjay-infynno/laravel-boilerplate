<?php

return [
    'hello' => 'Hello',
    // Personalised greeting used by the billing notifications. Falls back
    // gracefully: a user with no name renders "Hello there,".
    'greeting' => 'Hello :name,',
    'there' => 'there',
    'regards' => 'Best Regards,',
    'welcome_user' => [
        'subject' => 'Welcome to :app_name',
        'greeting' => 'Welcome to :app_name!',
        'content' => 'Thank you for registering with us. We\'re excited to have you on board!',
        'action' => 'Visit Website',
        'footer' => 'If you have any questions, please don\'t hesitate to contact us.',
    ],
    'forget_password' => [
        'subject' => 'Forgot Password Request',
        'line1' => 'We received a request to reset your password. If you did not make this request, please ignore this email.',
        'line2' => 'Your OTP is :otp. Please note that it is valid for the next :valid_minute minutes.',
        'footer' => 'If you have any questions, please contact our support team.',
    ],
    'email_verification' => [
        'subject' => 'Verify your email address',
        'line1' => 'Thanks for signing up. Enter the code below in the app to verify your email address and start your 30-day free trial.',
        'line2' => 'Your verification code is :otp. It is valid for the next :valid_minute minutes.',
        'footer' => 'If you did not create an account, you can safely ignore this email.',
    ],
    'app' => [
        'name' => config('app.name'),
    ],
];
