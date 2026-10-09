<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only, hash-chained per tenant. update() and delete() throw here as
 * defence in depth; the real guarantee is revoking UPDATE and DELETE on this
 * table for the application's database user.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit log entries cannot be deleted.'));
    }

    public static function computeHash(?string $prevHash, array $fields): string
    {
        return hash('sha256', implode('|', [
            $prevHash ?? '',
            $fields['event_type'],
            $fields['actor_type'],
            (string) ($fields['actor_id'] ?? ''),
            (string) ($fields['subject_type'] ?? ''),
            (string) ($fields['subject_id'] ?? ''),
            (string) ($fields['reference'] ?? ''),
            $fields['description'],
            json_encode($fields['metadata'] ?? [], JSON_UNESCAPED_SLASHES),
        ]));
    }
}
