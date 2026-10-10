<?php

return [

    // Length of the random part of a payment code, in digits.
    'code_length' => (int) env('PLATFORM_CODE_LENGTH', 12),

    // Keyed hash for stored codes. A bare hash of a 12-digit code could be
    // brute-forced offline, so the key is a secret that never leaves the server.
    'code_pepper' => env('PLATFORM_CODE_PEPPER', env('APP_KEY')),

    'api' => [
        'per_minute' => (int) env('PLATFORM_API_PER_MINUTE', 300),
        // Wrong or revoked keys, per source address. Keys are 160 bits so guessing is hopeless;
        // this only stops a flood of bad requests from costing a database lookup each.
        'bad_key_per_ip_per_minute' => (int) env('PLATFORM_API_BAD_KEY_PER_MINUTE', 30),
    ],

    // What an account number and a bank code must look like (regex bodies, no delimiters). The defaults
    // fit Nigerian NUBAN account numbers and CBN bank codes; change them for another market.
    'accounts' => [
        'number_pattern' => env('PLATFORM_ACCOUNT_NUMBER_PATTERN', '^\\d{10}$'),
        'bank_code_pattern' => env('PLATFORM_BANK_CODE_PATTERN', '^[A-Za-z0-9]{3,10}$'),
    ],

    // Country code assumed for phone numbers written without one.
    'default_country_code' => env('PLATFORM_DEFAULT_COUNTRY_CODE', '234'),

    // Redemption brute-force limits. Caller ID can be spoofed, so guessing from many made-up
    // numbers is bounded by a budget for callers who are NOT registered subscribers; a registered
    // subscriber's own calls are never starved by it. A genuine payer is therefore only exposed to
    // that budget when caller binding is off and they call from an unregistered phone.
    'redeem' => [
        'caller_max_failures' => (int) env('PLATFORM_REDEEM_CALLER_FAILURES', 5),
        'caller_decay_seconds' => 600,
        'tenant_max_per_minute' => (int) env('PLATFORM_REDEEM_TENANT_PER_MINUTE', 120),
        'unknown_caller_per_minute' => (int) env('PLATFORM_REDEEM_UNKNOWN_PER_MINUTE', 30),
    ],

    // Failed sign-ins across ALL addresses, per account, so guessing cannot be spread over many IPs.
    'login' => [
        'account_max_failures' => (int) env('PLATFORM_LOGIN_ACCOUNT_FAILURES', 20),
        'account_decay_seconds' => 1800,
        'second_factor_max_failures' => (int) env('PLATFORM_2FA_ACCOUNT_FAILURES', 10),
        'second_factor_decay_seconds' => 900,
    ],

    'admin' => [
        // null = required in production, optional elsewhere. Set true or false to force it.
        'require_two_factor' => env('PLATFORM_ADMIN_REQUIRE_2FA'),
    ],

    // Voice callbacks all arrive from the provider's servers, so a per-IP limit
    // would throttle every legitimate caller together. Limits are per number
    // (after the token is checked) and, for bad tokens only, per source address.
    'voice' => [
        'per_number_per_minute' => (int) env('PLATFORM_VOICE_PER_NUMBER_PER_MINUTE', 600),
        'bad_token_per_ip_per_minute' => 30,
    ],

    // Resolving captures that never reported back (see CodeService::reconcilePending).
    'reconcile' => [
        'after_minutes' => 2,
        'review_after_hours' => 24,
    ],

    'webhooks' => [
        // Seconds to wait before attempt 2, 3, ... Attempts stop after the last.
        'backoff' => array_map('intval', explode(',', env('PLATFORM_WEBHOOK_BACKOFF', '60,300,1800,7200,21600,43200'))),
        'timeout' => 10,
        'signature_tolerance' => 300,
        // Testing and local development only; live tenants must use public HTTPS.
        'allow_private_urls' => (bool) env('PLATFORM_ALLOW_PRIVATE_WEBHOOK_URLS', false),
    ],
];
