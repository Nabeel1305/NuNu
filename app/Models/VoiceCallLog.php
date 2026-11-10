<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class VoiceCallLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    /** What each reason means, for the person reading the log. */
    public const REASONS = [
        'number_not_registered' => 'The call came in on a number the platform does not have. Check the number in Voice numbers matches the one the provider sends calls to.',
        'number_inactive' => 'The number exists but is switched off.',
        'wrong_token' => 'The callback URL\'s token does not match this number (rotated, or the wrong URL was pasted into the provider or into PakaPay\'s VOICE_FORWARD_URL).',
        'rate_limited' => 'Too many attempts; the call was throttled.',
        'no_tenant' => 'No active tenant fits these digits. On a shared number the first four digits must be the tenant\'s short code.',
        'unknown_code' => 'No open code matches these digits: wrong digits, already used, or issued for a different tenant.',
        'not_redeemable' => 'The code is no longer open (already redeemed, cancelled or expired).',
        'expired' => 'The code had expired when the call arrived.',
        'caller_mismatch' => 'The caller\'s phone is not the payer\'s registered number, and this tenant requires it to match.',
        'device_codes_disabled' => 'This tenant has not switched on offline phone codes.',
        'direct_codes_disabled' => 'The bank has not switched on "pay any account" codes.',
        'malformed' => 'The digits are not a well-formed code.',
        'unknown_device' => 'The phone is not enrolled, or has been switched off.',
        'bad_signature' => 'The code did not verify (wrong key, wrong clock, or the account details changed).',
        'counter_reused' => 'This code (or an earlier one from the phone) was already used.',
        'amount_limit' => 'Above the allowed amount.',
        'daily_limit' => 'Above the daily limit.',
        'device_revoked' => 'The phone was switched off.',
        'amount_invalid' => 'The amount in the code is not valid.',
    ];

    /** Kept 30 days. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }
}
