<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $guarded = [];

    protected $hidden = ['key_hash'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    /**
     * Create a key for a tenant. The full key is returned once and only its
     * SHA-256 hash is stored; keys are high-entropy, so no slow hash is needed.
     *
     * @return array{0: ApiKey, 1: string}
     */
    public static function issue(Tenant $tenant, string $name): array
    {
        do {
            $prefix = Str::lower(bin2hex(random_bytes(4)));
        } while (static::where('prefix', $prefix)->exists());

        $plain = 'opk_' . $prefix . '_' . bin2hex(random_bytes(20));

        $key = static::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
        ]);

        return [$key, $plain];
    }

    /** Split a presented key into its lookup prefix, or null if malformed. */
    public static function prefixOf(string $plain): ?string
    {
        return preg_match('/^opk_([0-9a-f]{8})_[0-9a-f]{40}$/', $plain, $m) ? $m[1] : null;
    }

    public function matches(string $plain): bool
    {
        return hash_equals($this->key_hash, hash('sha256', $plain));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
