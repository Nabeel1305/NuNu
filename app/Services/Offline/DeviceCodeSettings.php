<?php

namespace App\Services\Offline;

use App\Models\Tenant;

/** Per-tenant switches for device-signed codes. Off until an operator turns them on. */
final class DeviceCodeSettings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $maxAmountMinor,
        public readonly int $dailyLimitMinor,
        public readonly bool $directEnabled = false,
        public readonly int $directMaxAmountMinor = 0,
    ) {
    }

    public static function for(Tenant $tenant): self
    {
        $max = (int) $tenant->setting('device_codes.max_amount_minor', \App\Support\PlatformSettings::effective('device_codes.max_amount_minor'));

        return new self(
            (bool) $tenant->setting('device_codes.enabled', false),
            $max,
            (int) $tenant->setting('device_codes.daily_limit_minor', \App\Support\PlatformSettings::effective('device_codes.daily_limit_minor')),
            // Paying an account the phone has never looked up: the bank or fintech switches it on and sets its own cap,
            // which can never exceed the tenant's overall per-payment cap.
            (bool) $tenant->setting('device_codes.direct_enabled', false),
            min($max, (int) $tenant->setting('device_codes.direct_max_amount_minor', \App\Support\PlatformSettings::effective('device_codes.direct_max_amount_minor'))),
        );
    }
}
