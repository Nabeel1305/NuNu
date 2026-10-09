<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'bind_caller' => 'boolean',
        'code_ttl_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        // The audit chain's lock row exists from the start, so writers never have to create it.
        static::created(fn (Tenant $tenant) => \Illuminate\Support\Facades\DB::table('audit_chain_heads')
            ->insert(['tenant_id' => $tenant->id, 'last_hash' => null]));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLive(): bool
    {
        return $this->environment === 'live';
    }

    public function usesSharedNumber(): bool
    {
        return $this->voice_mode === 'shared';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}
