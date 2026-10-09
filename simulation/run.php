<?php

// The simulator: plays partner apps and phone callers against a running
// instance (see run.sh). Every check prints PASS or FAIL; exit code is 1 on any FAIL.

require __DIR__ . '/../clients/php/src/ApiException.php';
require __DIR__ . '/../clients/php/src/Webhook.php';
require __DIR__ . '/../clients/php/src/Client.php';
require __DIR__ . '/../app/Services/Auth/Totp.php';

use OfflinePayments\ApiException;
use App\Services\Auth\Totp;
use OfflinePayments\Client;

$root = dirname(__DIR__);
$base = 'http://127.0.0.1:8099';
$creds = json_decode(file_get_contents(getenv('SIM_CREDS')), true);
$eventsFile = getenv('SIM_EVENTS');
$php = getenv('PHP_BIN') ?: 'php';
require __DIR__ . '/db.php';
$pdo = sim_pdo();
$driver = sim_driver();

$tenants = [];
foreach ($creds['tenants'] as $slug => $t) {
    $tenants[$t['name']] = $t + ['slug' => $slug];
}
$shared = $creds['shared_pool'];

$passed = 0;
$failed = 0;
$issuedCodes = [];   // every plain code the run ever saw, for the "never stored" check

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, ($ok || $detail === '') ? '' : "  [$detail]");
}

function section(string $title): void
{
    echo "\n== {$title}\n";
}

function client(string $name): Client
{
    global $tenants, $base;

    return new Client($tenants[$name]['api_key'], $base . '/api/v1');
}

/** Dial the voice endpoint like Africa's Talking would. @return array{0:int,1:string} */
function dial(string $number, string $token, ?string $digits, string $caller): array
{
    global $base;
    $fields = ['destinationNumber' => $number, 'callerNumber' => $caller, 'sessionId' => 'sim-' . bin2hex(random_bytes(3))];
    if ($digits !== null) {
        $fields['dtmfDigits'] = $digits;
    }

    $ch = curl_init($base . '/api/voice/africastalking?token=' . urlencode($token));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, $body];
}

function dialTenant(string $name, string $digits, string $caller): array
{
    global $tenants, $shared;
    $t = $tenants[$name];

    return $t['voice_mode'] === 'shared'
        ? dial($shared['number'], $shared['token'], $digits . '#', $caller)
        : dial($t['voice_number'], $t['voice_token'], $digits . '#', $caller);
}

function params(array $o = []): array
{
    return $o + ['subscriber_reference' => 'sub-1', 'merchant_reference' => 'shop-1', 'amount_minor' => 250000, 'currency' => 'NGN', 'source_account_reference' => 'acct-payer'];
}

function issue(string $name, array $o = [], ?string $key = null): array
{
    global $issuedCodes;
    $r = client($name)->issueCode(params($o), $key);
    $issuedCodes[] = $r['code'];

    return $r;
}

function events(): array
{
    global $eventsFile;
    $out = [];
    foreach (file($eventsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $out[] = json_decode($line, true);
    }

    return $out;
}

/** Events of a type about one code, from one tenant's receiver. */
function eventsFor(string $tenant, string $type, string $codeId): array
{
    return array_values(array_filter(events(), function ($e) use ($tenant, $type, $codeId) {
        $d = $e['event']['data'] ?? [];

        return $e['tenant'] === $tenant && ($e['event']['type'] ?? '') === $type && (($d['id'] ?? null) === $codeId || ($d['code_id'] ?? null) === $codeId);
    }));
}

function waitFor(callable $fn, int $seconds = 20): mixed
{
    $deadline = microtime(true) + $seconds;
    do {
        $v = $fn();
        if ($v) {
            return $v;
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);

    return null;
}

function q(string $sql, array $args = []): array
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($args);

    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function artisan(string $cmd): array
{
    global $php, $root;
    exec('cd ' . escapeshellarg($root) . " && {$php} artisan {$cmd} 2>&1", $out, $code);

    return [$code, implode("\n", $out)];
}

function setSettings(string $tenant, ?array $settings): void
{
    global $tenants;
    q('UPDATE tenants SET settings = ? WHERE id = ?', [$settings === null ? null : json_encode($settings), $tenants[$tenant]['id']]);
}

function pct(array $xs, float $p): float
{
    sort($xs);

    return $xs[(int) min(count($xs) - 1, floor($p * count($xs)))];
}


/** Fire requests at once. Each: ['url','method','headers'=>[], 'body'=>?string]. @return list<array{0:int,1:string}> */
function multi(array $requests): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $r) {
        $ch = curl_init($r['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CUSTOMREQUEST => $r['method'], CURLOPT_HTTPHEADER => $r['headers'] ?? []]);
        if (($r['body'] ?? null) !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $r['body']);
        }
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.5);
        }
    } while ($running);

    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_multi_getcontent($ch)];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    return $out;
}

function dialRequest(string $name, string $digits, string $caller): array
{
    global $tenants, $shared, $base;
    $t = $tenants[$name];
    [$number, $token] = $t['voice_mode'] === 'shared' ? [$shared['number'], $shared['token']] : [$t['voice_number'], $t['voice_token']];

    return [
        'url' => $base . '/api/voice/africastalking?token=' . urlencode($token), 'method' => 'POST',
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query(['destinationNumber' => $number, 'callerNumber' => $caller, 'dtmfDigits' => $digits . '#', 'sessionId' => 'sim-' . bin2hex(random_bytes(3))]),
    ];
}

function apiRequest(string $name, string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    global $tenants, $base;
    $headers = ['Authorization: Bearer ' . $tenants[$name]['api_key'], 'Accept: application/json', 'Content-Type: application/json'];
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    return ['url' => $base . '/api/v1' . $path, 'method' => $method, 'headers' => $headers, 'body' => $body === null ? null : json_encode($body)];
}

function placeholders(array $xs): string
{
    return implode(',', array_fill(0, count($xs), '?'));
}

$bank = 'Demo Bank';
$bankPhone = $tenants[$bank]['subscriber_phones']['sub-1'];
$started = microtime(true);

// ---------------------------------------------------------------------------
section('1. Happy path: issue, dial, settle, webhooks');
$issued = issue($bank);
$code = $issued['code'];
check('code is 12 digits', (bool) preg_match('/^\d{12}$/', $code));
check('new code is in state issued', $issued['state'] === 'issued');
[$status, $body] = dialTenant($bank, $code, $bankPhone);
check('call answered with the generic thank-you', $status === 200 && str_contains($body, 'Thank you'));
check('code is settled', client($bank)->getCode($issued['id'])['state'] === 'settled');
check('GET code never returns the digits', ! isset(client($bank)->getCode($issued['id'])['code']));

$settled = waitFor(fn () => eventsFor($bank, 'transaction.settled', $issued['id']));
$redeemed = waitFor(fn () => eventsFor($bank, 'code.redeemed', $issued['id']));
check('code.redeemed webhook arrived', (bool) $redeemed);
check('transaction.settled webhook arrived', (bool) $settled);
check('every webhook signature verified at the receiver', $redeemed && $settled && $redeemed[0]['valid'] && $settled[0]['valid']);
$txId = $settled[0]['event']['data']['id'] ?? '';
check('transaction readable by API and settled', $txId !== '' && client($bank)->getTransaction($txId)['status'] === 'settled');

// ---------------------------------------------------------------------------
section('2. A used code cannot be used again');
$before = (int) q('SELECT COUNT(*) c FROM transactions WHERE payment_code_id = (SELECT id FROM payment_codes WHERE uuid = ?)', [$issued['id']])[0]['c'];
[$status] = dialTenant($bank, $code, $bankPhone);
$after = (int) q('SELECT COUNT(*) c FROM transactions WHERE payment_code_id = (SELECT id FROM payment_codes WHERE uuid = ?)', [$issued['id']])[0]['c'];
check('replay answered the same way', $status === 200);
check('replay created no second transaction', $before === 1 && $after === 1, "before=$before after=$after");

// ---------------------------------------------------------------------------
section('3. Retrying a request is safe');
$a = issue($bank, [], 'sim-key-1');
$b = client($bank)->issueCode(params(), 'sim-key-1');
check('same Idempotency-Key returns the same code', $a['id'] === $b['id'] && $a['code'] === $b['code']);
check('only one code row exists for it', (int) q('SELECT COUNT(*) c FROM payment_codes WHERE uuid = ?', [$a['id']])[0]['c'] === 1);
try {
    client($bank)->issueCode(params(['amount_minor' => 1]), 'sim-key-1');
    check('same key with a different body is refused', false);
} catch (ApiException $e) {
    check('same key with a different body is refused', $e->status === 422 && $e->errorCode === 'idempotency_key_reused');
}
try {
    (new Client($tenants[$bank]['api_key'], $base . '/api/v1'))->issueCode(params(['amount_minor' => 0]), 'sim-key-2');
    check('invalid amount is refused', false);
} catch (ApiException $e) {
    check('invalid amount is refused', $e->status === 422);
}

// ---------------------------------------------------------------------------
section('4. The tenant\'s system refuses the hold');
setSettings($bank, ['sandbox' => ['fail_hold' => true]]);
try {
    issue($bank);
    check('issue refused when the hold is rejected', false);
} catch (ApiException $e) {
    check('issue refused when the hold is rejected', $e->status === 422 && $e->errorCode === 'settlement_rejected');
}
setSettings($bank, null);
check('no code row was created for the refused hold', (int) q('SELECT COUNT(*) c FROM payment_codes WHERE tenant_id = ? AND state = ?', [$tenants[$bank]['id'], 'failed'])[0]['c'] === 0);

// ---------------------------------------------------------------------------
section('5. The tenant declines the capture');
$d = 'Declining Bank';
$issuedD = issue($d);
dialTenant($d, $issuedD['code'], '+2348055500001');
check('code ends failed', client($d)->getCode($issuedD['id'])['state'] === 'failed');
$ev = waitFor(fn () => eventsFor($d, 'transaction.failed', $issuedD['id']));
check('transaction.failed webhook arrived and verified', $ev && $ev[0]['valid']);
check('failure reason recorded', ($ev[0]['event']['data']['failure_reason'] ?? '') !== '');

// ---------------------------------------------------------------------------
section('6. Cancelling');
$c = issue($bank);
check('cancel succeeds', client($bank)->cancelCode($c['id'])['state'] === 'cancelled');
dialTenant($bank, $c['code'], $bankPhone);
check('a cancelled code cannot be redeemed', client($bank)->getCode($c['id'])['state'] === 'cancelled');
try {
    client($bank)->cancelCode($issued['id']);
    check('a settled code cannot be cancelled', false);
} catch (ApiException $e) {
    check('a settled code cannot be cancelled', $e->status === 409 && $e->errorCode === 'not_cancellable');
}

// ---------------------------------------------------------------------------
section('7. Guessing attack on the shared number');
$f = 'Demo Fintech';
$fp = $tenants[$f]['short_code'];
$legit = issue($f);
check('shared-pool code starts with the tenant\'s short code', str_starts_with($legit['code'], $fp));
$attacker = '+2348099999999';
foreach (range(1, 5) as $i) {
    dialTenant($f, $fp . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT), $attacker);
}
dialTenant($f, $legit['code'], $attacker);
check('after 5 wrong guesses the attacker is ignored, even with the right code', client($f)->getCode($legit['id'])['state'] === 'issued');
dialTenant($f, $legit['code'], '+2348022222222');
check('the real payer, from another number, is unaffected', client($f)->getCode($legit['id'])['state'] === 'settled');

// ---------------------------------------------------------------------------
section('8. Tenants sharing one number stay separate');
$w = 'Demo Wallet';
$wc = issue($w);
$fc = issue($f);
dialTenant($w, $wc['code'], '+2348033333333');
check('wallet\'s code settled', client($w)->getCode($wc['id'])['state'] === 'settled');
check('fintech\'s code untouched', client($f)->getCode($fc['id'])['state'] === 'issued');
try {
    client($f)->getCode($wc['id']);
    check('one tenant cannot read another\'s code', false);
} catch (ApiException $e) {
    check('one tenant cannot read another\'s code', $e->status === 404);
}
try {
    client($bank)->cancelCode($fc['id']);
    check('one tenant cannot cancel another\'s code', false);
} catch (ApiException $e) {
    check('one tenant cannot cancel another\'s code', $e->status === 404);
}
check('wallet\'s webhook never carried fintech data', array_reduce(events(), fn ($c, $e) => $c || ($e['tenant'] === $w && (($e['event']['data']['id'] ?? '') === $fc['id'] || ($e['event']['data']['code_id'] ?? '') === $fc['id'])), false) === false);

// ---------------------------------------------------------------------------
section('9. Voice endpoint authentication');
[$s1] = dial($tenants[$bank]['voice_number'], 'wrong-token', '123456789012#', $bankPhone);
[$s2] = dial('+2340000000000', $tenants[$bank]['voice_token'], '123456789012#', $bankPhone);
[$s3, $b3] = dial($tenants[$bank]['voice_number'], $tenants[$bank]['voice_token'], null, $bankPhone);
check('wrong token is refused', $s1 === 403);
check('unknown number is refused', $s2 === 403);
check('a call with no digits is asked for them', $s3 === 200 && str_contains($b3, 'GetDigits'));
$bound = issue($bank);
dialTenant($bank, $bound['code'], '+2348099999998');
check('caller binding: another phone cannot redeem', client($bank)->getCode($bound['id'])['state'] === 'issued');
dialTenant($bank, $bound['code'], preg_replace('/^\+234/', '0', $bankPhone));
check('caller binding: the payer\'s own phone (written 0…) can', client($bank)->getCode($bound['id'])['state'] === 'settled');

// ---------------------------------------------------------------------------
section('10. Expiry');
$e = issue($bank);
q('UPDATE payment_codes SET expires_at = ? WHERE uuid = ?', [gmdate('Y-m-d H:i:s', time() - 60), $e['id']]);
dialTenant($bank, $e['code'], $bankPhone);
check('an overdue code cannot be redeemed even before the sweep runs', client($bank)->getCode($e['id'])['state'] === 'issued');
[$exit, $out] = artisan('codes:expire');
check('codes:expire ran', $exit === 0 && str_contains($out, 'expired'), $out);
check('code is now expired', client($bank)->getCode($e['id'])['state'] === 'expired');
check('code.expired webhook arrived', (bool) waitFor(fn () => eventsFor($bank, 'code.expired', $e['id'])));

// ---------------------------------------------------------------------------
section('11. A capture result that never came back is reconciled');
$r = issue($bank);
dialTenant($bank, $r['code'], $bankPhone);
$rowId = q('SELECT id FROM payment_codes WHERE uuid = ?', [$r['id']])[0]['id'];
q("UPDATE payment_codes SET state = 'redeemed' WHERE id = ?", [$rowId]);
q("UPDATE transactions SET status = 'pending', settled_at = NULL, settlement_reference = NULL, created_at = ? WHERE payment_code_id = ?", [gmdate('Y-m-d H:i:s', time() - 600), $rowId]);
check('simulated lost result leaves the code redeemed', client($bank)->getCode($r['id'])['state'] === 'redeemed');
setSettings($bank, ['sandbox' => ['status' => 'unknown']]);
[, $out] = artisan('transactions:reconcile');
check('"unknown" from the tenant changes nothing', client($bank)->getCode($r['id'])['state'] === 'redeemed', $out);
setSettings($bank, ['sandbox' => ['status' => 'captured']]);
[, $out] = artisan('transactions:reconcile');
check('"captured" from the tenant settles it', client($bank)->getCode($r['id'])['state'] === 'settled', $out);
check('the late settlement was announced', count(waitFor(fn () => count(eventsFor($bank, 'transaction.settled', $r['id'])) >= 2 ? eventsFor($bank, 'transaction.settled', $r['id']) : null) ?? []) >= 2);
setSettings($bank, null);

// ---------------------------------------------------------------------------
section('12. A flaky partner server still gets every event');
$fe = eventsFor($f, 'transaction.settled', $legit['id']);
$delivery = waitFor(function () use ($tenants, $f) {
    $rows = q("SELECT status, attempts FROM webhook_deliveries WHERE tenant_id = ? AND event_type = 'transaction.settled' ORDER BY id LIMIT 1", [$tenants[$f]['id']]);

    return ($rows && $rows[0]['status'] === 'delivered') ? $rows[0] : null;
}, 30);
check('delivered after the first attempt failed', $delivery && (int) $delivery['attempts'] >= 2, json_encode($delivery));
check('receiver saw the failed attempt and the retry', count($fe) >= 2, 'seen=' . count($fe));
$ids = array_map(fn ($x) => $x['event']['id'], $fe);
check('the retry carries the same event id (partners can de-duplicate)', count($ids) >= 2 && count(array_unique($ids)) === 1);

// ---------------------------------------------------------------------------
section('13. Audit trail');
[$exit, $out] = artisan('audit:verify');
check('every tenant\'s audit chain is intact', $exit === 0, $out);
$row = q('SELECT id, description FROM audit_logs ORDER BY id LIMIT 1 OFFSET 3')[0];
q('UPDATE audit_logs SET description = ? WHERE id = ?', ['tampered', $row['id']]);
[$exit, $out] = artisan('audit:verify');
check('tampering with one entry is detected', $exit === 1 && str_contains($out, (string) $row['id']), $out);
q('UPDATE audit_logs SET description = ? WHERE id = ?', [$row['description'], $row['id']]);
[$exit] = artisan('audit:verify');
check('chain verifies again once restored', $exit === 0);

// ---------------------------------------------------------------------------
section('14. Admin dashboard');
$jar = tempnam(sys_get_temp_dir(), 'sim');
$http = function (string $method, string $path, array $form = []) use ($base, $jar): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HEADER => false]);
    if ($method === 'POST') {
        // CURLOPT_POST, not CUSTOMREQUEST: a custom method would be re-sent as POST after the redirect.
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    }
    $body = (string) curl_exec($ch);
    $res = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    curl_close($ch);

    return $res;
};
$token = fn (string $html) => preg_match('/name="_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';

[, $html, $url] = $http('GET', '/admin/tenants');
check('guests are sent to the login page', str_ends_with($url, '/admin/login'));
[, $html] = $http('POST', '/admin/login', ['_token' => $token($html), 'email' => $creds['admin']['email'], 'password' => 'wrong-password']);
check('a wrong password is refused with a generic message', str_contains($html, 'These credentials do not match'));
[, $html] = $http('POST', '/admin/login', ['_token' => $token($html), 'email' => $creds['admin']['email'], 'password' => $creds['admin']['password']]);
check('the admin signs in and sees the tenant list', str_contains($html, 'Demo Bank') && str_contains($html, 'Declining Bank'));
[$code200, $html] = $http('GET', '/admin/tenants/' . $tenants[$bank]['id']);
check('tenant page shows keys, numbers, deliveries and audit log', $code200 === 200 && str_contains($html, 'API keys') && str_contains($html, 'Recent webhook deliveries') && str_contains($html, 'code.issued'));
check('the page never shows a full API key', ! str_contains($html, $tenants[$bank]['api_key']));
[, $html] = $http('POST', '/admin/tenants/' . $tenants[$bank]['id'] . '/verify-audit', ['_token' => $token($html)]);
check('the dashboard can verify the audit chain', str_contains($html, 'Audit chain is intact'));

[, $html] = $http('GET', '/admin/security');
check('security page offers an authenticator key', (bool) preg_match('#<code>([A-Z2-7 ]{20,})</code>#', $html));
preg_match('#<code>([A-Z2-7 ]{20,})</code>#', $html, $sm);
$totpSecret = str_replace(' ', '', $sm[1] ?? '');
[, $html] = $http('POST', '/admin/security', ['_token' => $token($html), 'code' => '000000']);
check('enrolment refuses a wrong code', str_contains($html, 'not valid'));
[, $html] = $http('POST', '/admin/security', ['_token' => $token($html), 'code' => Totp::code($totpSecret, Totp::step(time()))]);
check('enrolment accepts the app\'s code and turns two-factor on', str_contains($html, 'Two-factor authentication is on'));
[, $html] = $http('POST', '/admin/logout', ['_token' => $token($html)]);
[, $html] = $http('GET', '/admin/login');
[, $html, $url] = $http('POST', '/admin/login', ['_token' => $token($html), 'email' => $creds['admin']['email'], 'password' => $creds['admin']['password']]);
check('after the password, the admin is asked for a code and is not signed in', str_ends_with($url, '/admin/two-factor'));
[, $page, $url2] = $http('GET', '/admin/tenants');
check('the dashboard stays closed until the code is given', str_ends_with($url2, '/admin/login') || str_ends_with($url2, '/admin/two-factor'));
[, $html, $url] = $http('POST', '/admin/two-factor', ['_token' => $token($html), 'code' => '111111']);
check('a wrong code is refused', str_contains($html, 'not valid'));
// Enrolment used the current 30-second step, and a used step cannot be replayed, so the next
// sign-in uses the following step's code (inside the allowed window), as a person would after waiting.
$nextCode = fn () => Totp::code($totpSecret, Totp::step(time()) + 1);
$signInCode = $nextCode();
[, $html, $url] = $http('POST', '/admin/two-factor', ['_token' => $token($html), 'code' => $signInCode]);
check('the app\'s code completes the sign-in', str_contains($html, 'Demo Bank') && str_ends_with($url, '/admin/tenants'), $url);
[, $html] = $http('POST', '/admin/logout', ['_token' => $token($html)]);
[, $html] = $http('GET', '/admin/login');
$http('POST', '/admin/login', ['_token' => $token($html), 'email' => $creds['admin']['email'], 'password' => $creds['admin']['password']]);
[, $html] = $http('GET', '/admin/two-factor');
[, $html, $url] = $http('POST', '/admin/two-factor', ['_token' => $token($html), 'code' => $signInCode]);
check('the code that just worked cannot be used again', ! str_ends_with($url, '/admin/tenants'), $url);

// ---------------------------------------------------------------------------
section('15. Volume: 60 payments in a row');
$issueMs = [];
$dialMs = [];
$ok = 0;
$t0 = microtime(true);
foreach (range(1, 60) as $n) {
    $s = microtime(true);
    $x = issue($w, ['subscriber_reference' => 'sub-' . (($n % 5) + 1), 'merchant_reference' => 'shop-' . (($n % 2) + 1), 'amount_minor' => 1000 * $n]);
    $issueMs[] = (microtime(true) - $s) * 1000;
    $s = microtime(true);
    dialTenant($w, $x['code'], '+23480' . str_pad((string) (7000000 + $n), 8, '0', STR_PAD_LEFT));
    $dialMs[] = (microtime(true) - $s) * 1000;
    $ok += client($w)->getCode($x['id'])['state'] === 'settled' ? 1 : 0;
}
$elapsed = microtime(true) - $t0;
check('all 60 payments settled', $ok === 60, "$ok/60");
printf("  info  issue p50 %.0f ms, p95 %.0f ms; dial+settle p50 %.0f ms, p95 %.0f ms; %.1f payments/s (%s)\n",
    pct($issueMs, .5), pct($issueMs, .95), pct($dialMs, .5), pct($dialMs, .95), 60 / $elapsed, $driver === 'mysql' ? '8 web workers, MariaDB' : 'single worker, SQLite');


// ---------------------------------------------------------------------------
if ($driver === 'mysql') {
    section('17. Concurrency on real row locks');
    $w = 'Demo Wallet';
    $oneTx = fn (string $codeId) => (int) q('SELECT COUNT(*) c FROM transactions WHERE payment_code_id = (SELECT id FROM payment_codes WHERE uuid = ?)', [$codeId])[0]['c'];

    // a) many callers dial one code at the same moment
    $exactlyOnce = 0;
    $serverErrors = 0;
    foreach (range(1, 8) as $round) {
        $x = issue($w);
        $reqs = array_map(fn ($n) => dialRequest($w, $x['code'], '+23480' . str_pad((string) (9000000 + $round * 100 + $n), 8, '0', STR_PAD_LEFT)), range(1, 12));
        foreach (multi($reqs) as [$status]) {
            $serverErrors += $status >= 500 ? 1 : 0;
        }
        $state = client($w)->getCode($x['id'])['state'];
        $exactlyOnce += ($oneTx($x['id']) === 1 && $state === 'settled') ? 1 : 0;
    }
    check('12 simultaneous dials on one code produce exactly one payment (8 rounds)', $exactlyOnce === 8, "$exactlyOnce/8");
    check('no server error under that contention', $serverErrors === 0, "5xx=$serverErrors");

    // b) the same Idempotency-Key sent ten times at once
    $amount = 777000 + random_int(1, 999);
    $key = 'race-' . bin2hex(random_bytes(4));
    $replies = multi(array_map(fn () => apiRequest($w, 'POST', '/codes', params(['amount_minor' => $amount]), $key), range(1, 10)));
    $statuses = array_count_values(array_column($replies, 0));
    $ids = [];
    foreach ($replies as [$st, $bodyText]) {
        if ($st === 201) {
            $j = json_decode($bodyText, true);
            $ids[$j['id']] = true;
            $issuedCodes[] = $j['code'];
        }
    }
    $rows = (int) q('SELECT COUNT(*) c FROM payment_codes WHERE tenant_id = ? AND amount_minor = ?', [$tenants[$w]['id'], $amount])[0]['c'];
    check('ten simultaneous retries create exactly one code', $rows === 1 && count($ids) === 1, "rows=$rows ids=" . count($ids) . ' statuses=' . json_encode($statuses));
    check('the others were told it is in progress or got the same answer', array_sum(array_diff_key($statuses, [201 => 1, 409 => 1])) === 0, json_encode($statuses));

    // c) cancel and dial at the same moment: one wins, never both
    $tally = ['cancelled' => 0, 'settled' => 0];
    $inconsistent = 0;
    foreach (range(1, 12) as $n) {
        $x = issue($w);
        $res = multi([
            apiRequest($w, 'POST', "/codes/{$x['id']}/cancel", null, 'cx-' . bin2hex(random_bytes(4))),
            dialRequest($w, $x['code'], '+23480' . str_pad((string) (9500000 + $n), 8, '0', STR_PAD_LEFT)),
        ]);
        $state = client($w)->getCode($x['id'])['state'];
        $tx = $oneTx($x['id']);
        $tally[$state] = ($tally[$state] ?? 0) + 1;
        $consistent = ($state === 'cancelled' && $tx === 0 && $res[0][0] === 200) || ($state === 'settled' && $tx === 1 && $res[0][0] === 409);
        $inconsistent += $consistent ? 0 : 1;
    }
    check('cancel racing a dial never leaves a half state (12 rounds)', $inconsistent === 0, "inconsistent=$inconsistent");
    printf("  info  outcomes: %d cancelled first, %d paid first\n", $tally['cancelled'], $tally['settled']);

    // d) parallel issuing across tenants, then the audit chains
    $reqs = [];
    foreach (range(1, 10) as $n) {
        foreach ([$bank, $f, $w] as $name) {
            $reqs[] = apiRequest($name, 'POST', '/codes', params(['amount_minor' => 5000 + $n]), 'par-' . bin2hex(random_bytes(6)));
        }
    }
    $made = multi($reqs);
    foreach ($made as [$st, $bodyText]) {
        if ($st === 201) {
            $issuedCodes[] = json_decode($bodyText, true)['code'];
        }
    }
    check('30 parallel issues across three tenants all succeeded', count(array_filter($made, fn ($m) => $m[0] === 201)) === 30, json_encode(array_count_values(array_column($made, 0))));
    [$exit, $out] = artisan('audit:verify');
    check('audit chains are intact after all that parallel writing', $exit === 0, $out);
    $forks = (int) q('SELECT COUNT(*) c FROM (SELECT tenant_id, prev_hash FROM audit_logs WHERE prev_hash IS NOT NULL GROUP BY tenant_id, prev_hash HAVING COUNT(*) > 1) d')[0]['c'];
    $roots = (int) q('SELECT COUNT(*) c FROM (SELECT tenant_id FROM audit_logs WHERE prev_hash IS NULL GROUP BY tenant_id HAVING COUNT(*) > 1) d')[0]['c'];
    check('no audit chain ever forked', $forks === 0 && $roots === 0, "forks=$forks roots=$roots");

    // e) throughput with eight payments in flight
    $uuids = [];
    $t0 = microtime(true);
    foreach (range(1, 12) as $wave) {
        $issueReplies = multi(array_map(fn ($n) => apiRequest($w, 'POST', '/codes', params(['amount_minor' => 100 * $n]), 'tp-' . bin2hex(random_bytes(6))), range(1, 8)));
        $wave8 = [];
        foreach ($issueReplies as $n => [$st, $bodyText]) {
            if ($st === 201) {
                $j = json_decode($bodyText, true);
                $issuedCodes[] = $j['code'];
                $uuids[] = $j['id'];
                $wave8[] = dialRequest($w, $j['code'], '+23480' . str_pad((string) (8000000 + $wave * 10 + $n), 8, '0', STR_PAD_LEFT));
            }
        }
        multi($wave8);
    }
    $elapsed = microtime(true) - $t0;
    $settled = (int) q('SELECT COUNT(*) c FROM payment_codes WHERE state = ? AND uuid IN (' . placeholders($uuids) . ')', array_merge(['settled'], $uuids))[0]['c'];
    check('96 payments with 8 in flight all settled', count($uuids) === 96 && $settled === 96, "issued=" . count($uuids) . " settled=$settled");
    printf("  info  %.1f payments/s with 8 in flight (8 web workers, 2 queue workers, MariaDB)\n", 96 / $elapsed);
} else {
    section('17. Concurrency on real row locks');
    echo "  skip  needs MySQL (SQLite has no row locks); run with SIM_DB=mysql\n";
}

// ---------------------------------------------------------------------------
section('16. Invariants across the whole run');
$bad = q("SELECT COUNT(*) c FROM transactions t JOIN payment_codes p ON p.id = t.payment_code_id WHERE (t.status = 'settled' AND p.state != 'settled') OR (t.status = 'failed' AND p.state != 'failed')")[0]['c'];
check('every settled/failed transaction matches its code\'s state', (int) $bad === 0, "mismatches=$bad");
$dupes = q('SELECT COUNT(*) c FROM (SELECT payment_code_id FROM transactions GROUP BY payment_code_id HAVING COUNT(*) > 1) d')[0]['c'];
check('no code ever produced two transactions', (int) $dupes === 0);
$orphan = q("SELECT COUNT(*) c FROM payment_codes WHERE state = 'redeemed' AND id NOT IN (SELECT payment_code_id FROM transactions)")[0]['c'];
check('no redeemed code without a transaction', (int) $orphan === 0);
$dump = '';
foreach (q($driver === 'mysql' ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
    $table = array_values($row)[0];
    foreach (q("SELECT * FROM `{$table}`") as $r) {
        $dump .= json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
$dbBytes = $dump;
$leaked = array_filter($issuedCodes, fn ($c) => str_contains($dbBytes, $c));
check('none of the ' . count($issuedCodes) . ' plain codes appears in any table', $leaked === [], count($leaked) . ' found');
$allValid = array_reduce(events(), fn ($c, $e) => $c && $e['valid'], true);
check('all ' . count(events()) . ' webhook deliveries had a valid signature', $allValid);

printf("\nSimulation finished in %.1fs: %d passed, %d failed.\n", microtime(true) - $started, $passed, $failed);
exit($failed === 0 ? 0 : 1);
