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
    ) {
    }

    public static function for(Tenant $tenant): self
    {
        return new self(
            (bool) $tenant->setting('device_codes.enabled', false),
            (int) $tenant->setting('device_codes.max_amount_minor', config('platform.device_codes.max_amount_minor')),
            (int) $tenant->setting('device_codes.daily_limit_minor', config('platform.device_codes.daily_limit_minor')),
        );
    }
}
