<?php

namespace Tests\Unit;

use OfflinePayments\ApiException;
use OfflinePayments\Client;
use OfflinePayments\Webhook;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../clients/php/src/ApiException.php';
require_once __DIR__ . '/../../clients/php/src/Webhook.php';
require_once __DIR__ . '/../../clients/php/src/Client.php';

class PhpClientTest extends TestCase
{
    private function client(array &$seen, int $status = 201, string $body = '{"id":"c1","code":"123456789012"}'): Client
    {
        return new Client('opk_test', 'https://api.test/api/v1/', function ($method, $url, $headers, $payload) use (&$seen, $status, $body) {
            $seen = compact('method', 'url', 'headers', 'payload');

            return [$status, $body];
        });
    }

    public function test_issue_sends_the_key_the_body_and_an_idempotency_key(): void
    {
        $seen = [];
        $result = $this->client($seen)->issueCode(['amount_minor' => 100], 'fixed-key');

        $this->assertSame('123456789012', $result['code']);
        $this->assertSame('POST', $seen['method']);
        $this->assertSame('https://api.test/api/v1/codes', $seen['url']);
        $this->assertContains('Authorization: Bearer opk_test', $seen['headers']);
        $this->assertContains('Idempotency-Key: fixed-key', $seen['headers']);
        $this->assertSame('{"amount_minor":100}', $seen['payload']);
    }

    public function test_each_call_without_a_key_gets_a_fresh_idempotency_key(): void
    {
        $keys = [];
        $client = new Client('k', 'https://x', function ($m, $u, $headers) use (&$keys) {
            $keys[] = array_values(array_filter($headers, fn ($h) => str_starts_with($h, 'Idempotency-Key')))[0];

            return [201, '{}'];
        });

        $client->issueCode([]);
        $client->issueCode([]);

        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_an_error_response_becomes_an_exception_with_the_code(): void
    {
        $seen = [];

        try {
            $this->client($seen, 422, '{"error":{"code":"settlement_rejected","message":"No funds"}}')->issueCode([]);
            $this->fail('Expected an exception.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('settlement_rejected', $e->errorCode);
            $this->assertSame('No funds', $e->getMessage());
        }
    }

    public function test_path_values_are_encoded(): void
    {
        $seen = [];
        $this->client($seen, 200, '{}')->upsertSubscriber('a b/c', '+234');

        $this->assertSame('https://api.test/api/v1/subscribers/a%20b%2Fc', $seen['url']);
    }

    public function test_webhook_verification_matches_the_platform_signer(): void
    {
        $platform = new \App\Services\Webhooks\WebhookSigner();
        $header = $platform->header('whsec_x', '{"a":1}', 1_000);

        $this->assertTrue(Webhook::verify('whsec_x', $header, '{"a":1}', 300, 1_050));
        $this->assertFalse(Webhook::verify('whsec_x', $header, '{"a":2}', 300, 1_050));
        $this->assertFalse(Webhook::verify('whsec_x', $header, '{"a":1}', 300, 2_000));
    }

    public function test_verifies_the_shared_fixed_vector(): void
    {
        // Same vector asserted in clients/js/index.test.js.
        $this->assertTrue(Webhook::verify('whsec_x', 't=1000,v1=8e34e23174d8364a4b53cfafdf794866781da3c798cb1437af1ff66b34777988', '{"a":1}', 300, 1000));
    }
}
