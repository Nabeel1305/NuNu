<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceNumber extends Model
{
    protected $guarded = [];

    protected $hidden = ['webhook_token_hash'];

    protected $casts = ['active' => 'boolean'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The number a payer should ring for this tenant's codes: its own active number, or, when it
     * shares the pool, an active pool number. Null when none is set up yet.
     */
    public static function forDialing(Tenant $tenant): ?self
    {
        return static::query()
            ->where('active', true)
            ->where('provider', \App\Services\Voice\AfricasTalkingAdapter::PROVIDER)
            ->when(
                $tenant->usesSharedNumber(),
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $tenant->id),
            )
            ->orderBy('id')
            ->first();
    }

    /** E.164 with a leading "+". */
    public function e164(): string
    {
        return '+' . ltrim($this->number, '+');
    }

    public function isSharedPool(): bool
    {
        return $this->tenant_id === null;
    }

    public function acceptsToken(string $presented): bool
    {
        return hash_equals($this->webhook_token_hash, hash('sha256', $presented));
    }
}
