<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\PaymentCode;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Codes\CodeService;
use App\Services\Codes\CodeState;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** A redeemed code with a pending transaction: what a capture that never reported back leaves behind. */
    private function stuck(string $sandboxStatus): array
    {
        [$tenant] = $this->makeTenant(['settings' => ['sandbox' => ['status' => $sandboxStatus]]]);
        $this->seedParties($tenant);

        return app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $code = PaymentCode::create([
                'uuid' => (string) Str::uuid(),
                'subscriber_id' => Subscriber::first()->id,
                'merchant_id' => Merchant::first()->id,
                'amount_minor' => 1000, 'currency' => 'NGN',
                'source_account_reference' => 'acct-payer',
                'code_hash' => hash('sha256', Str::random()),
                'state' => CodeState::Redeemed,
                'hold_reference' => 'hold_1',
                'expires_at' => now()->addMinutes(10),
                'redeemed_at' => now(),
            ]);

            $transaction = Transaction::create([
                'uuid' => (string) Str::uuid(),
                'payment_code_id' => $code->id,
                'reference' => 'TXN-' . Str::upper(Str::random(12)),
                'amount_minor' => 1000, 'currency' => 'NGN', 'status' => 'pending',
            ]);

            return [$tenant, $code, $transaction];
        });
    }

    public function test_a_captured_answer_settles_the_transaction(): void
    {
        [, $code, $transaction] = $this->stuck('captured');
        $this->travel(3)->minutes();

        $result = app(CodeService::class)->reconcilePending();

        $this->assertSame(1, $result['settled']);
        $this->assertSame('settled', $transaction->fresh()->status);
        $this->assertSame(CodeState::Settled, $code->fresh()->state);
    }

    public function test_a_released_answer_fails_the_transaction(): void
    {
        [, $code, $transaction] = $this->stuck('released');
        $this->travel(3)->minutes();

        $this->assertSame(1, app(CodeService::class)->reconcilePending()['failed']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(CodeState::Failed, $code->fresh()->state);
    }

    public function test_an_unknown_answer_leaves_it_pending(): void
    {
        [, , $transaction] = $this->stuck('unknown');
        $this->travel(3)->minutes();

        $result = app(CodeService::class)->reconcilePending();

        $this->assertSame(['settled' => 0, 'failed' => 0, 'retried' => 0, 'flagged' => 0], $result);
        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_a_transaction_that_is_too_new_is_left_alone(): void
    {
        [, , $transaction] = $this->stuck('captured');

        app(CodeService::class)->reconcilePending();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_an_unanswered_transaction_is_flagged_once_after_a_day(): void
    {
        [$tenant, , $transaction] = $this->stuck('unknown');
        $this->travel(25)->hours();

        $this->assertSame(1, app(CodeService::class)->reconcilePending()['flagged']);
        $this->assertSame(0, app(CodeService::class)->reconcilePending()['flagged']);

        $this->assertSame(1, AuditLog::where('event_type', 'transaction.needs_review')->where('reference', $transaction->reference)->count());
        $this->assertSame('pending', $transaction->fresh()->status);
    }
}
