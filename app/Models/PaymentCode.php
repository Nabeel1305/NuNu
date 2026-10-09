<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Codes\CodeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentCode extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    // The hash never leaves the server, in API output or anywhere else.
    protected $hidden = ['code_hash'];

    protected $casts = [
        'state' => CodeState::class,
        'amount_minor' => 'integer',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    /** Move to a new state, refusing any transition the state machine does not allow. */
    public function transitionTo(CodeState $next, array $attributes = []): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new \LogicException("Payment code cannot move from {$this->state->value} to {$next->value}.");
        }

        $this->update(['state' => $next] + $attributes);
    }
}
