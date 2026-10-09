<?php

// A stand-in for partners' webhook servers. Verifies every delivery's signature
// with the tenant's secret and records what arrived. Router script for `php -S`.

require __DIR__ . '/../clients/php/src/Webhook.php';

use OfflinePayments\Webhook;

$creds = json_decode(file_get_contents(getenv('SIM_CREDS')), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (! preg_match('#^/hook/([^/]+)$#', $path, $m) || ! isset($creds['tenants'][$m[1]])) {
    http_response_code(404);
    return;
}

$slug = $m[1];
$tenant = $creds['tenants'][$slug];
$raw = file_get_contents('php://input');
$valid = Webhook::verify($tenant['webhook_secret'], $_SERVER['HTTP_OFFLINE_SIGNATURE'] ?? '', $raw);
$event = json_decode($raw, true);

file_put_contents(getenv('SIM_EVENTS'), json_encode(['tenant' => $tenant['name'], 'valid' => $valid, 'event' => $event]) . "\n", FILE_APPEND | LOCK_EX);

if (! $valid) {
    http_response_code(401);
    return;
}

// Demo Fintech's server is flaky: it fails the first delivery of every event, to
// exercise retries. Everyone else answers straight away.
if ($tenant['name'] === 'Demo Fintech') {
    $seenFile = dirname(getenv('SIM_EVENTS')) . '/simulation-seen.json';
    $seen = is_file($seenFile) ? json_decode(file_get_contents($seenFile), true) : [];

    if (! in_array($event['id'], $seen, true)) {
        $seen[] = $event['id'];
        file_put_contents($seenFile, json_encode($seen), LOCK_EX);
        http_response_code(500);
        echo 'try again';

        return;
    }
}

echo 'ok';
