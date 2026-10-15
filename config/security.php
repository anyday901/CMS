<?php

return [
    // Failed logins from one IP address to one account before that address
    // is locked out of it for a while. Set on the admin Security page too.
    'lockout_attempts' => (int) env('SECURITY_LOCKOUT_ATTEMPTS', 5),
    'lockout_minutes' => (int) env('SECURITY_LOCKOUT_MINUTES', 15),

    // Ban an IP address that fails this many logins in an hour, to any
    // accounts, for this many hours. Null turns automatic bans off.
    'ban_after_failures' => env('SECURITY_BAN_AFTER_FAILURES', 20),
    'ban_hours' => (int) env('SECURITY_BAN_HOURS', 24),

    // Login history is deleted after this many days, every night.
    'login_history_days' => (int) env('SECURITY_LOGIN_HISTORY_DAYS', 90),

    // Staff without two-factor authentication have to set it up before
    // they can use the admin area.
    'staff_two_factor' => (bool) env('SECURITY_STAFF_TWO_FACTOR', false),

    // A CAPTCHA on public forms: off (null), turnstile, hcaptcha or recaptcha.
    'captcha' => [
        'provider' => env('CAPTCHA_PROVIDER'),
        'site_key' => env('CAPTCHA_SITE_KEY'),
        'secret_key' => env('CAPTCHA_SECRET_KEY'),
        // Which forms show it: login, register, password, contact, staff_login.
        'forms' => ['login', 'register', 'password', 'contact'],
    ],
];
