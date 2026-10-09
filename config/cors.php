<?php

// Browsers may call the API (bearer API key, no cookies). By default every origin is allowed;
// set PLATFORM_CORS_ORIGINS to a comma-separated list (https://app.example.com,https://admin.example.com)
// to restrict it, or to an empty string to switch browser access off. The dashboard and portal
// are same-origin and unaffected.
$origins = env('PLATFORM_CORS_ORIGINS', '*');

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $origins)))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 600,
    // Credentials must stay off: with "*" browsers refuse them anyway, and the API never uses cookies.
    'supports_credentials' => false,
];
