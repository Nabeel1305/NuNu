<?php

namespace App\Services\Offline;

use App\Models\OfflineDevice;

/**
 * Derives a phone's secret key from a platform secret, so no per-device key is stored: a database leak
 * reveals no key. The platform secret is PLATFORM_DEVICE_KEY_SECRET; keep it in a secret manager or an HSM
 * (the derivation is a plain HMAC, so a KMS "GenerateMac" key is a drop-in replacement for this class).
 * Changing it invalidates every enrolled device.
 */
class DeviceKeyService
{
    /** @return string 32 raw bytes */
    public function derive(OfflineDevice $device): string
    {
        return hash_hmac(
            'sha256',
            'nunu-device-key|v' . (int) $device->key_version . "|{$device->tenant_id}|{$device->short_id}|{$device->device_id}",
            $this->secret(),
            true,
        );
    }

    private function secret(): string
    {
        $configured = (string) config('platform.device_key_secret');

        // Falling back to a value derived from APP_KEY keeps development working; production should set its own.
        return $configured !== '' ? $configured : hash_hmac('sha256', 'nunu-device-key-master', (string) config('app.key'), true);
    }
}
