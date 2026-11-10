<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide defaults an owner can change on the console without touching the server's environment.
 * A saved value wins over the environment/config default; clearing it goes back to the default.
 */
final class PlatformSettings
{
    /** key => [label, kind, config default key]. kind: money (shown in naira, stored in kobo) | int */
    public const DEFINITIONS = [
        'device_codes.max_amount_minor' => ['Largest offline payment to a known recipient', 'money', 'platform.device_codes.max_amount_minor'],
        'device_codes.direct_max_amount_minor' => ['Default cap to an account the phone never looked up', 'money', 'platform.device_codes.direct_max_amount_minor'],
        'device_codes.daily_limit_minor' => ['Daily offline total per subscriber', 'money', 'platform.device_codes.daily_limit_minor'],
        'device_codes.max_devices_per_subscriber' => ['Phones per subscriber', 'int', 'platform.device_codes.max_devices_per_subscriber'],
        'admin.idle_minutes' => ['Sign operators out after idle minutes (0 = never)', 'int', 'platform.admin.idle_minutes'],
        'alerts.stuck_minutes' => ['Alert when a payment is pending this many minutes', 'int', 'platform.alerts.stuck_minutes'],
        'alerts.webhook_failures' => ['Alert at this many failed webhook deliveries in an hour', 'int', 'platform.alerts.webhook_failures'],
        'alerts.failed_payments' => ['Alert at this many failed payments in an hour', 'int', 'platform.alerts.failed_payments'],
    ];

    private const CACHE_KEY = 'platform:settings';

    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                return Schema::hasTable('platform_settings') ? PlatformSetting::pluck('value', 'key')->all() : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? (int) $all[$key] : $default;
    }

    public static function put(string $key, ?int $value, ?int $adminId = null): void
    {
        abort_unless(isset(self::DEFINITIONS[$key]), 422, 'Unknown setting.');

        $value === null
            ? PlatformSetting::where('key', $key)->delete()
            : PlatformSetting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'updated_by' => $adminId]);

        Cache::forget(self::CACHE_KEY);
    }

    /** The effective value: saved, else the config default. */
    public static function effective(string $key): int
    {
        return (int) self::get($key, config(self::DEFINITIONS[$key][2]));
    }
}
