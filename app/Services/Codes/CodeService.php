<?php

namespace App\Services\Codes;

use App\Models\Merchant;
use App\Models\PaymentCode;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Audit\AuditLogService;
use App\Services\Settlement\AccountRef;
use App\Services\Settlement\SettlementManager;
use App\Services\Settlement\SettlementRejected;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Mask;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CodeService
{
    public function __construct(
        private readonly CodeGenerator $generator,
        private readonly SettlementManager $settlement,
        private readonly AuditLogService $audit,
        private readonly WebhookDispatcher $webhooks,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * Issue a one-time code and place a hold for it. Returns the model and the
     * plain code; the plain code exists only in this return value.
     *
     * Funds are held on the subscriber's registered account and will be credited to the merchant's.
     * Both accounts are frozen on the code now, so editing a merchant later cannot redirect a payment
     * already in flight.
     *
     * The merchant may be registered on the spot: pass `merchant` => [name, account_number, bank_code]
     * and an unknown merchant_reference is created together with the code (and only if the hold
     * succeeds). An existing merchant is never changed this way, and a different account is refused,
     * because the account to credit must not be rewritable by a payment request.
     *
     * @param array{subscriber_reference:string, merchant_reference:string, merchant?:array{name:string, account_number:string, bank_code:string, account_reference?:?string}, amount_minor:int, currency:string, source_account_reference?:?string} $data
     * @return array{0: PaymentCode, 1: string, 2: bool} the code, its plain digits, and whether the merchant was created
     */
    public function issue(Tenant $tenant, array $data): array
    {
        return $this->context->run($tenant, function () use ($tenant, $data) {
            $subscriber = Subscriber::where('reference', $data['subscriber_reference'])->firstOrFail();
            if (blank($subscriber->account_number) || blank($subscriber->bank_code)) {
                throw ValidationException::withMessages(['subscriber_reference' =>
                    "Subscriber {$subscriber->reference} has no bank account on file. Register it with PUT /subscribers/{$subscriber->reference} (account_number and bank_code)."]);
            }

            $inline = $data['merchant'] ?? null;
            $merchant = Merchant::where('reference', $data['merchant_reference'])->first();

            if ($merchant === null && $inline === null) {
                Merchant::where('reference', $data['merchant_reference'])->firstOrFail();   // the usual 404
            }
            if ($merchant !== null && blank($merchant->account_number)) {
                throw ValidationException::withMessages(['merchant_reference' =>
                    "Merchant {$merchant->reference} has no bank account on file. Register it with PUT /merchants/{$merchant->reference} (name, account_number and bank_code)."]);
            }
            if ($merchant !== null && $inline !== null) {
                $this->assertSameAccount($merchant, $inline);
            }

            $max = $tenant->setting('max_amount_minor');
            if ($max !== null && $data['amount_minor'] > (int) $max) {
                throw new SettlementRejected('Amount is above this tenant\'s limit.');
            }

            $uuid = (string) Str::uuid();
            $adapter = $this->settlement->for($tenant);

            $hold = $adapter->hold(
                $tenant, $uuid,
                new AccountRef($subscriber->account_number, $subscriber->bank_code, $data['source_account_reference'] ?? null),
                $data['amount_minor'], strtoupper($data['currency'])
            );

            if (! $hold->ok) {
                throw new SettlementRejected($hold->reason ?? 'The hold was rejected.');
            }

            try {
                return DB::transaction(function () use ($tenant, $data, $subscriber, $merchant, $inline, $uuid, $hold) {
                    // Local copy: if the transaction is retried after a deadlock, start from what the caller found.
                    $resolved = $merchant;
                    $merchantCreated = false;
                    if ($resolved === null) {
                        // Another request may register the same merchant at the same moment: createOrFirst
                        // takes whichever row won, and we then check it credits the account we were given.
                        $resolved = Merchant::createOrFirst(
                            ['reference' => $data['merchant_reference']],
                            [
                                'name' => $inline['name'],
                                'account_number' => $inline['account_number'],
                                'bank_code' => $inline['bank_code'],
                                'account_reference' => $inline['account_reference'] ?? null,
                            ],
                        );
                        $merchantCreated = $resolved->wasRecentlyCreated;

                        if ($merchantCreated) {
                            $this->audit->record($tenant->id, 'merchant.created', 'tenant', null, Merchant::class, $resolved->id,
                                "Merchant {$resolved->reference} registered while issuing a code", ['account_number' => Mask::account($resolved->account_number), 'bank_code' => $resolved->bank_code], $resolved->reference);
                        } else {
                            $this->assertSameAccount($resolved, $inline);
                        }
                    }

                    $prefix = $tenant->usesSharedNumber() ? $tenant->short_code : '';

                    do {
                        $plain = $this->generator->generate($prefix);
                        $hash = $this->generator->hash($plain);
                    } while (PaymentCode::where('code_hash', $hash)->exists());

                    $code = PaymentCode::create([
                        'uuid' => $uuid,
                        'subscriber_id' => $subscriber->id,
                        'merchant_id' => $resolved->id,
                        'amount_minor' => $data['amount_minor'],
                        'currency' => strtoupper($data['currency']),
                        'source_account_reference' => $data['source_account_reference'] ?? $subscriber->account_number,
                        'source_account_number' => $subscriber->account_number,
                        'source_bank_code' => $subscriber->bank_code,
                        'destination_account_number' => $resolved->account_number,
                        'destination_bank_code' => $resolved->bank_code,
                        'code_hash' => $hash,
                        'state' => CodeState::Issued,
                        'hold_reference' => $hold->reference,
                        'expires_at' => now()->addMinutes($tenant->code_ttl_minutes),
                    ]);

                    $this->audit->record($tenant->id, 'code.issued', 'tenant', null, PaymentCode::class, $code->id,
                        "Code issued for {$code->amount_minor} {$code->currency}", ['uuid' => $uuid], $uuid);

                    return [$code, $plain, $merchantCreated];
                }, 3);
            } catch (\Throwable $e) {
                // The hold was placed but we could not record the code: give the money back.
                $adapter->release($tenant, $hold->reference);

                throw $e;
            }
        });
    }

    /** @param array{account_number:string, bank_code:string} $inline */
    private function assertSameAccount(Merchant $merchant, array $inline): void
    {
        if ($merchant->account_number !== $inline['account_number'] || $merchant->bank_code !== $inline['bank_code']) {
            throw ValidationException::withMessages([
                'merchant.account_number' => "Merchant {$merchant->reference} is already registered with a different account. "
                    . "Change it with PUT /merchants/{$merchant->reference}, or leave the merchant details out of this request.",
            ]);
        }
    }

    /** Cancel an unredeemed code and release its hold. */
    public function cancel(Tenant $tenant, string $uuid): PaymentCode
    {
        return $this->context->run($tenant, function () use ($tenant, $uuid) {
            $code = DB::transaction(function () use ($tenant, $uuid) {
                $code = PaymentCode::where('uuid', $uuid)->lockForUpdate()->firstOrFail();

                $code->transitionTo(CodeState::Cancelled);

                $this->audit->record($tenant->id, 'code.cancelled', 'tenant', null, PaymentCode::class, $code->id,
                    'Code cancelled by the tenant', [], $code->uuid);

                return $code;
            }, 3);

            $this->releaseHold($tenant, $code);

            return $code;
        });
    }

    /** Expire every issued code past its deadline. Returns how many were expired. */
    public function expireDue(): int
    {
        $count = 0;

        PaymentCode::withoutGlobalScopes()
            ->where('state', CodeState::Issued->value)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($codes) use (&$count) {
                foreach ($codes as $row) {
                    $tenant = Tenant::find($row->tenant_id);

                    $this->context->run($tenant, function () use ($tenant, $row, &$count) {
                        $expired = DB::transaction(function () use ($tenant, $row) {
                            $code = PaymentCode::whereKey($row->id)->lockForUpdate()->first();

                            if (! $code || $code->state !== CodeState::Issued) {
                                return null;
                            }

                            $code->transitionTo(CodeState::Expired);

                            $this->audit->record($tenant->id, 'code.expired', 'system', null, PaymentCode::class, $code->id,
                                'Code expired unused', [], $code->uuid);

                            return $code;
                        }, 3);

                        if ($expired) {
                            $this->releaseHold($tenant, $expired);
                            $this->webhooks->dispatch($tenant, 'code.expired', $this->codeData($expired));
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }

    /**
     * Redeem a code from a voice call. Every failure looks the same to the
     * caller; the reason on the outcome is for logs and tests.
     */
    public function redeem(Tenant $tenant, string $rawDigits, string $callerNumber): RedeemOutcome
    {
        return $this->context->run($tenant, function () use ($tenant, $rawDigits, $callerNumber) {
            $limits = config('platform.redeem');
            $callerNumberKey = PhoneNumber::normalize($callerNumber);
            $callerKey = 'redeem:caller:' . $tenant->id . ':' . ($callerNumberKey ?: 'withheld');

            // Anyone can fake a caller number, so a flood of guesses from made-up numbers must not be
            // able to use up the budget real payers depend on. Callers who are registered subscribers
            // count against the normal tenant budget; everyone else shares a small separate one.
            $known = $callerNumberKey !== '' && Subscriber::where('phone_normalized', $callerNumberKey)->exists();
            $tenantKey = ($known ? 'redeem:tenant:' : 'redeem:tenant-unknown:') . $tenant->id;
            $tenantLimit = $known ? $limits['tenant_max_per_minute'] : $limits['unknown_caller_per_minute'];

            if (RateLimiter::tooManyAttempts($callerKey, $limits['caller_max_failures'])
                || RateLimiter::tooManyAttempts($tenantKey, $tenantLimit)) {
                return RedeemOutcome::rejected('rate_limited');
            }

            RateLimiter::hit($tenantKey, 60);

            $hash = $this->generator->hash($rawDigits);

            $claimed = DB::transaction(function () use ($tenant, $hash, $callerNumber) {
                $code = PaymentCode::where('code_hash', $hash)->lockForUpdate()->first();

                if (! $code) {
                    return RedeemOutcome::rejected('unknown_code');
                }

                if ($code->state !== CodeState::Issued) {
                    return RedeemOutcome::rejected('not_redeemable');
                }

                if ($code->expires_at->isPast()) {
                    return RedeemOutcome::rejected('expired');
                }

                if ($tenant->bind_caller && ! PhoneNumber::same($code->subscriber->phone, $callerNumber)) {
                    return RedeemOutcome::rejected('caller_mismatch');
                }

                $code->transitionTo(CodeState::Redeemed, [
                    'redeemed_at' => now(),
                    'caller_number' => $callerNumber,
                ]);

                Transaction::create([
                    'uuid' => (string) Str::uuid(),
                    'payment_code_id' => $code->id,
                    'reference' => 'TXN-' . strtoupper(Str::random(12)),
                    'amount_minor' => $code->amount_minor,
                    'currency' => $code->currency,
                    'status' => 'pending',
                ]);

                $this->audit->record($tenant->id, 'code.redeemed', 'caller', null, PaymentCode::class, $code->id,
                    'Code redeemed by voice call', [], $code->uuid);

                return RedeemOutcome::accepted($code);
            }, 3);

            if (! $claimed->accepted) {
                RateLimiter::hit($callerKey, $limits['caller_decay_seconds']);

                return $claimed;
            }

            RateLimiter::clear($callerKey);

            $code = $claimed->code->fresh(['transaction', 'merchant']);

            $this->webhooks->dispatch($tenant, 'code.redeemed', $this->codeData($code));

            // The capture call happens outside the row lock: it is a network call
            // to the tenant and must not hold a database lock open.
            $this->capture($tenant, $code);

            return $claimed;
        });
    }

    /** The merchant account frozen on the code; codes issued before that was recorded fall back to the merchant's current one. */
    private function destinationOf(PaymentCode $code): AccountRef
    {
        $merchant = $code->merchant;

        return new AccountRef(
            (string) ($code->destination_account_number ?? $merchant->account_number),
            (string) ($code->destination_bank_code ?? $merchant->bank_code),
            $merchant->account_reference,
        );
    }

    private function capture(Tenant $tenant, PaymentCode $code): void
    {
        $transaction = $code->transaction;

        try {
            $result = $this->settlement->for($tenant)->capture(
                $tenant, $code->hold_reference, $this->destinationOf($code), $transaction->reference
            );
        } catch (\Throwable $e) {
            // Outcome unknown: leave the code redeemed and the transaction pending
            // so reconcilePending() can ask the tenant, rather than guess.
            Log::error('Settlement capture did not complete', ['transaction' => $transaction->reference, 'error' => $e->getMessage()]);

            return;
        }

        $result->ok
            ? $this->finishSettled($tenant, $code, $transaction, $result->reference)
            : $this->finishFailed($tenant, $code, $transaction, $result->reason ?? 'Settlement was declined.', release: true);
    }

    /**
     * Settle a pending transaction. Re-checks under a lock, so a late capture
     * and a reconciliation pass can never both settle the same transaction.
     */
    private function finishSettled(Tenant $tenant, PaymentCode $code, Transaction $transaction, ?string $settlementReference): bool
    {
        $done = DB::transaction(function () use ($tenant, $code, $transaction, $settlementReference) {
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $locked->update(['status' => 'settled', 'settlement_reference' => $settlementReference, 'settled_at' => now()]);
            $code->fresh()->transitionTo(CodeState::Settled);

            $this->audit->record($tenant->id, 'transaction.settled', 'system', null, Transaction::class, $locked->id,
                'Transaction settled by the tenant', ['settlement_reference' => $settlementReference], $locked->reference);

            return true;
        }, 3);

        if ($done) {
            $this->webhooks->dispatch($tenant, 'transaction.settled', $this->transactionData($transaction->fresh(), $code));
        }

        return $done;
    }

    private function finishFailed(Tenant $tenant, PaymentCode $code, Transaction $transaction, string $reason, bool $release): bool
    {
        $done = DB::transaction(function () use ($tenant, $code, $transaction, $reason) {
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $locked->update(['status' => 'failed', 'failure_reason' => $reason]);
            $code->fresh()->transitionTo(CodeState::Failed);

            $this->audit->record($tenant->id, 'transaction.failed', 'system', null, Transaction::class, $locked->id,
                'Settlement did not complete', ['reason' => $reason], $locked->reference);

            return true;
        }, 3);

        if ($done) {
            if ($release) {
                $this->releaseHold($tenant, $code);
            }

            $this->webhooks->dispatch($tenant, 'transaction.failed', $this->transactionData($transaction->fresh(), $code));
        }

        return $done;
    }

    /**
     * Resolve transactions whose capture call never reported back. Asks the
     * tenant what happened to each one and acts only on a clear answer; an
     * unclear one stays pending, and is flagged once for a person to review
     * after platform.reconcile.review_after_hours.
     *
     * @return array{settled:int, failed:int, retried:int, flagged:int}
     */
    public function reconcilePending(): array
    {
        $counts = ['settled' => 0, 'failed' => 0, 'retried' => 0, 'flagged' => 0];
        $cfg = config('platform.reconcile');

        Transaction::withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes($cfg['after_minutes']))
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$counts, $cfg) {
                foreach ($rows as $row) {
                    $tenant = Tenant::find($row->tenant_id);

                    $this->context->run($tenant, function () use ($tenant, $row, &$counts, $cfg) {
                        $transaction = Transaction::with('paymentCode.merchant')->find($row->id);
                        $code = $transaction?->paymentCode;

                        if (! $transaction || $transaction->status !== 'pending' || $code?->state !== CodeState::Redeemed) {
                            return;
                        }

                        try {
                            $status = $this->settlement->for($tenant)->status($tenant, $transaction->reference);
                        } catch (\Throwable $e) {
                            Log::error('Reconciliation status check failed', ['transaction' => $transaction->reference, 'error' => $e->getMessage()]);

                            return;
                        }

                        switch ($status->state) {
                            case 'captured':
                                $this->finishSettled($tenant, $code, $transaction, $status->reference) && $counts['settled']++;
                                break;

                            case 'released':
                                // The tenant already gave the money back, so nothing to release.
                                $this->finishFailed($tenant, $code, $transaction, 'The tenant released the hold.', release: false) && $counts['failed']++;
                                break;

                            case 'held':
                                // Not captured yet. Capture is keyed by the transaction reference,
                                // so asking again cannot move the money twice.
                                $counts['retried']++;
                                $this->capture($tenant, $code);
                                break;

                            default:
                                if ($transaction->created_at->lte(now()->subHours($cfg['review_after_hours'])) && $this->flagForReview($tenant, $transaction)) {
                                    $counts['flagged']++;
                                }
                        }
                    });
                }
            });

        return $counts;
    }

    private function flagForReview(Tenant $tenant, Transaction $transaction): bool
    {
        $already = \App\Models\AuditLog::where('tenant_id', $tenant->id)
            ->where('event_type', 'transaction.needs_review')
            ->where('reference', $transaction->reference)
            ->exists();

        if ($already) {
            return false;
        }

        $this->audit->record($tenant->id, 'transaction.needs_review', 'system', null, Transaction::class, $transaction->id,
            'No answer from the tenant on the outcome; needs manual review', [], $transaction->reference);

        Log::critical('Transaction needs manual review', ['tenant' => $tenant->id, 'transaction' => $transaction->reference]);

        return true;
    }

    private function releaseHold(Tenant $tenant, PaymentCode $code): void
    {
        try {
            $this->settlement->for($tenant)->release($tenant, $code->hold_reference);
        } catch (\Throwable $e) {
            // Logged for follow-up; the state change has already been committed.
            Log::error('Could not release hold', ['code' => $code->uuid, 'error' => $e->getMessage()]);
        }
    }

    public function codeData(PaymentCode $code): array
    {
        return [
            'id' => $code->uuid,
            'state' => $code->state->value,
            'amount_minor' => $code->amount_minor,
            'currency' => $code->currency,
            'expires_at' => $code->expires_at->toIso8601String(),
        ];
    }

    public function transactionData(Transaction $transaction, PaymentCode $code): array
    {
        return [
            'id' => $transaction->uuid,
            'code_id' => $code->uuid,
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->currency,
            'settlement_reference' => $transaction->settlement_reference,
            'failure_reason' => $transaction->failure_reason,
        ];
    }
}
