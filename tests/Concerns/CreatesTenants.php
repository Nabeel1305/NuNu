<?php

namespace Tests\Concerns;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\VoiceNumber;
use App\Support\TenantContext;

trait CreatesTenants
{
    /** @return array{0: Tenant, 1: string} the tenant and its plain API key */
    protected function makeTenant(array $attributes = []): array
    {
        static $n = 0;
        $n++;

        $tenant = Tenant::create($attributes + [
            'name' => "Tenant {$n}",
            'slug' => "tenant-{$n}",
            'short_code' => str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT),
        ]);

        [, $plain] = ApiKey::issue($tenant, 'test');

        return [$tenant, $plain];
    }

    /** Register a payer and a merchant for a tenant. */
    protected function seedParties(Tenant $tenant, string $phone = '+2348012345678'): void
    {
        app(TenantContext::class)->run($tenant, function () use ($phone) {
            Subscriber::create(['reference' => 'sub-1', 'phone' => $phone]);
            Merchant::create(['reference' => 'shop-1', 'name' => 'Corner Shop', 'account_reference' => 'acct-shop']);
        });
    }

    /** @return string the callback token for the number */
    protected function makeVoiceNumber(string $number, ?Tenant $tenant = null): string
    {
        $token = bin2hex(random_bytes(8));

        VoiceNumber::create([
            'tenant_id' => $tenant?->id,
            'number' => $number,
            'webhook_token_hash' => hash('sha256', $token),
        ]);

        return $token;
    }

    protected function issueBody(array $overrides = []): array
    {
        return $overrides + [
            'subscriber_reference' => 'sub-1',
            'merchant_reference' => 'shop-1',
            'amount_minor' => 250000,
            'currency' => 'NGN',
            'source_account_reference' => 'acct-payer',
        ];
    }

    protected function issueCode(string $key, array $overrides = [], ?string $idempotencyKey = null)
    {
        return $this->withToken($key)
            ->withHeader('Idempotency-Key', $idempotencyKey ?? bin2hex(random_bytes(6)))
            ->postJson('/api/v1/codes', $this->issueBody($overrides));
    }

    protected function dial(string $number, string $token, string $digits, string $caller = '+2348012345678')
    {
        return $this->post("/api/voice/africastalking?token={$token}", [
            'destinationNumber' => $number,
            'callerNumber' => $caller,
            'dtmfDigits' => $digits,
            'sessionId' => 'sess-1',
        ]);
    }
}
