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

    // Device-signed offline codes. Nothing is reserved when such a code is built (the phone has no
    // connection), so the money is only checked when the call arrives; these caps limit the exposure.
    // Each tenant can override them (and must be switched on) in the operator console.
    'device_codes' => [
        'max_amount_minor' => (int) env('PLATFORM_DEVICE_CODE_MAX_AMOUNT_MINOR', 2_000_000),      // ₦20,000 per payment
        'direct_max_amount_minor' => (int) env('PLATFORM_DEVICE_CODE_DIRECT_MAX_AMOUNT_MINOR', 500_000),  // ₦5,000 to an account the phone never looked up
        'daily_limit_minor' => (int) env('PLATFORM_DEVICE_CODE_DAILY_LIMIT_MINOR', 5_000_000),    // ₦50,000 per subscriber per day
        'max_devices_per_subscriber' => (int) env('PLATFORM_DEVICE_CODE_MAX_DEVICES', 5),
        'currency' => env('PLATFORM_DEVICE_CODE_CURRENCY', 'NGN'),
    ],

    // No separate queue worker? Set true and the scheduler (which must be running) also drains the queue every minute, so webhooks go out
    // within about a minute. A real `queue:work` worker is faster and is the recommended setup; this is the fallback.
    'queue_in_scheduler' => (bool) env('PLATFORM_QUEUE_IN_SCHEDULER', false),

    // Set to a long random value to let an external cron service run the schedule by calling POST /cron/tick every minute
    // (see CronController). Leave empty when a scheduler process (`schedule:work`) is running instead.
    'cron_token' => env('PLATFORM_CRON_TOKEN'),

    // Alerts to operators. Slack incoming-webhook URL and/or a comma-separated list of emails (needs working MAIL_*).
    'alerts' => [
        'slack_webhook' => env('PLATFORM_ALERT_SLACK_WEBHOOK'),
        'emails' => env('PLATFORM_ALERT_EMAILS'),
        'stuck_minutes' => (int) env('PLATFORM_ALERT_STUCK_MINUTES', 30),
        'webhook_failures' => (int) env('PLATFORM_ALERT_WEBHOOK_FAILURES', 5),
        'failed_payments' => (int) env('PLATFORM_ALERT_FAILED_PAYMENTS', 10),
        'repeat_hours' => (int) env('PLATFORM_ALERT_REPEAT_HOURS', 6),
    ],

    // Root secret the phones' keys are derived from. Set it once, keep it in a secret manager, never lose it.
    'device_key_secret' => env('PLATFORM_DEVICE_KEY_SECRET', ''),

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
        // An operator left idle this long is signed out. 0 turns it off.
        'idle_minutes' => (int) env('PLATFORM_ADMIN_IDLE_MINUTES', 60),
    ],

    // Voice callbacks all arrive from the provider's servers, so a per-IP limit
    // would throttle every legitimate caller together. Limits are per number
    // (after the token is checked) and, for bad tokens only, per source address.
    'voice' => [
        'per_number_per_minute' => (int) env('PLATFORM_VOICE_PER_NUMBER_PER_MINUTE', 600),
        'bad_token_per_ip_per_minute' => 30,
        // TEMPORARY SWITCH. true (the default): a callback must carry the number's secret ?token=. false: the dialled number alone
        // identifies the tenant. Anyone who knows the callback address can then submit digits as if they were the provider, so
        // use it only while setting up (e.g. behind PakaPay's forward) and turn it back on.
        'require_token' => (bool) env('PLATFORM_VOICE_REQUIRE_TOKEN', true),
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
