<?php

namespace Tests\Unit;

use App\Services\Webhooks\UrlGuard;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.webhooks.allow_private_urls' => false]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blocked')]
    public function test_internal_and_non_https_addresses_are_refused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new UrlGuard())->assertPublic($url);
    }

    public static function blocked(): array
    {
        return [
            'plain http' => ['http://8.8.8.8/hook'],
            'loopback' => ['https://127.0.0.1/hook'],
            'private 10/8' => ['https://10.0.0.5/hook'],
            'private 192.168/16' => ['https://192.168.1.10/hook'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data'],
            'no host' => ['https:///hook'],
            'not a url' => ['not a url'],
        ];
    }

    public function test_a_public_address_is_accepted_and_returned_for_pinning(): void
    {
        $this->assertSame(['8.8.8.8'], (new UrlGuard())->assertPublic('https://8.8.8.8/hook'));
    }

    public function test_the_check_can_be_switched_off_for_tests_only(): void
    {
        config(['platform.webhooks.allow_private_urls' => true]);

        $this->assertSame([], (new UrlGuard())->assertPublic('http://127.0.0.1/hook'));
    }
}
