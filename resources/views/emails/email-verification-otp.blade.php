@extends('emails.layouts.master')

@section('content')
    <p>{{ __('email.email_verification.line1') }}</p>

    <p>{{ __('email.email_verification.line2', [
        'otp' => $otp,
        'valid_minute' => config('site.otp.expiration_time_in_minutes'),
    ]) }}
    </p>

    <p>{{ __('email.email_verification.footer') }}</p>
@endsection
