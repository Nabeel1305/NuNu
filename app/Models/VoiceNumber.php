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

    public function isSharedPool(): bool
    {
        return $this->tenant_id === null;
    }

    public function acceptsToken(string $presented): bool
    {
        return hash_equals($this->webhook_token_hash, hash('sha256', $presented));
    }
}
