<?php

use App\Integrations\Sms\Providers\LogOnly;
use App\Integrations\Sms\Providers\Twilio;
use App\Integrations\Sms\Providers\Vonage;

return [
    // Text message services staff can pick under Settings > SMS. Each class
    // implements App\Integrations\Sms\SmsProvider.
    'providers' => [
        Twilio::class,
        Vonage::class,
        LogOnly::class,
        // App\Sms\MyProvider::class,
    ],

    // The rest is set under Settings > SMS.
    'provider' => env('SMS_PROVIDER'),
    'from' => env('SMS_FROM'),
    // JSON of the provider's credentials, saved encrypted.
    'credentials' => null,
    // Added to client phone numbers written without a country code, such as "1" for the US.
    'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '1'),
    'staff_numbers' => [],
    'staff_events' => [],
    'client_events' => [],
];
