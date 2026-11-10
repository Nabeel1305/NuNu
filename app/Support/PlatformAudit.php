<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;

final class PlatformAudit
{
    /** Never written to the log, whatever the form carried. */
    private const SECRET_FIELDS = ['password', 'password_confirmation', 'current_password', 'code', '_token', '_method', 'secret', 'token'];

    public static function record(?Admin $admin, string $event, string $description, ?int $tenantId = null, ?string $subject = null, array $metadata = [], string $outcome = 'ok', ?string $ip = null, ?string $email = null): void
    {
        PlatformAuditLog::create([
            'admin_id' => $admin?->id,
            'admin_email' => $admin?->email ?? $email,
            'event' => $event,
            'tenant_id' => $tenantId,
            'subject' => $subject,
            'description' => mb_substr($description, 0, 500),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip' => $ip ?? request()->ip(),
            'outcome' => $outcome,
        ]);
    }

    /** The form fields that were submitted, by name, with secrets left out and long values cut. */
    public static function inputSummary(Request $request): array
    {
        $out = [];
        foreach ($request->except(self::SECRET_FIELDS) as $k => $v) {
            $out[$k] = is_scalar($v) || $v === null ? mb_substr((string) $v, 0, 120) : '[…]';
        }

        return $out;
    }
}
