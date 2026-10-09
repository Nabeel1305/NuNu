<?php

namespace Tests\Unit;

use App\Services\Webhooks\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase
{
    private const SECRET = 'whsec_test';

    public function test_a_fresh_signature_verifies(): void
    {
        $signer = new WebhookSigner();
        $header = $signer->header(self::SECRET, '{"a":1}', 1_000);

        $this->assertTrue($signer->verify(self::SECRET, $header, '{"a":1}', 300, 1_100));
    }

    public function test_a_changed_body_fails(): void
    {
        $signer = new WebhookSigner();
        $header = $signer->header(self::SECRET, '{"a":1}', 1_000);

        $this->assertFalse($signer->verify(self::SECRET, $header, '{"a":2}', 300, 1_000));
    }

    public function test_the_wrong_secret_fails(): void
    {
        $signer = new WebhookSigner();
        $header = $signer->header(self::SECRET, '{}', 1_000);

        $this->assertFalse($signer->verify('whsec_other', $header, '{}', 300, 1_000));
    }

    public function test_a_stale_delivery_is_rejected_even_with_a_valid_signature(): void
    {
        $signer = new WebhookSigner();
        $header = $signer->header(self::SECRET, '{}', 1_000);

        $this->assertFalse($signer->verify(self::SECRET, $header, '{}', 300, 1_400));
    }

    public function test_a_malformed_header_fails(): void
    {
        $this->assertFalse((new WebhookSigner())->verify(self::SECRET, 'garbage', '{}', 300, 1_000));
    }
}
