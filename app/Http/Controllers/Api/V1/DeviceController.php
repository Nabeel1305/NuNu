<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OfflineDevice;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Services\Audit\AuditLogService;
use App\Services\Offline\DeviceCodeFormat;
use App\Services\Offline\DeviceCodeSettings;
use App\Services\Offline\DeviceKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Enrol a subscriber's phone so it can build offline payment codes with no connection. The response is
 * the only place the phone's secret key is ever shown, and it carries `last_counter` so a reinstalled
 * app knows where to resume. Re-enrolling an active device returns the same key (it is derived, not stored).
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceKeyService $keys, private readonly AuditLogService $audit)
    {
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->assertEnabled($tenant);

        $data = $request->validate(['device_id' => ['required', 'string', 'min:8', 'max:191']]);
        $subscriber = Subscriber::where('reference', $reference)->firstOrFail();

        if (blank($subscriber->account_number) || blank($subscriber->bank_code)) {
            throw ValidationException::withMessages(['reference' => "Subscriber {$reference} has no bank account on file. Register it with PUT /subscribers/{$reference} first."]);
        }

        $device = OfflineDevice::where('subscriber_id', $subscriber->id)->where('device_id', $data['device_id'])->first();
        $status = 200;

        if ($device === null) {
            $max = (int) config('platform.device_codes.max_devices_per_subscriber');
            if (OfflineDevice::where('subscriber_id', $subscriber->id)->where('status', 'active')->count() >= $max) {
                throw ValidationException::withMessages(['device_id' => "A subscriber can have at most {$max} active devices. Revoke one first."]);
            }

            $device = OfflineDevice::create([
                'subscriber_id' => $subscriber->id,
                'device_id' => $data['device_id'],
                'short_id' => OfflineDevice::newShortId($tenant->id),
            ])->fresh();   // reload: the database defaults (status, key_version, counter) must be on the model before the key is derived
            $status = 201;
            $this->record($request, 'device.enrolled', "Device enrolled for {$reference}", $device);
        } elseif (! $device->isActive()) {
            // Coming back after a revoke: a new key, and the counter starts again.
            $device->update(['status' => 'active', 'revoked_at' => null, 'key_version' => $device->key_version + 1, 'last_counter' => 0]);
            $status = 201;
            $this->record($request, 'device.enrolled', "Device re-enrolled for {$reference} with a new key", $device);
        }

        return response()->json($this->present($device, $subscriber) + [
            'device_key' => base64_encode($this->keys->derive($device)),
            'currency' => strtoupper((string) config('platform.device_codes.currency')),
            'code_length' => DeviceCodeFormat::LENGTH,
            'window_seconds' => DeviceCodeFormat::WINDOW_SECONDS,
        ], $status);
    }

    public function index(Request $request, string $reference): JsonResponse
    {
        $subscriber = Subscriber::where('reference', $reference)->firstOrFail();

        return response()->json([
            'data' => OfflineDevice::where('subscriber_id', $subscriber->id)->orderBy('id')->get()
                ->map(fn (OfflineDevice $d) => $this->present($d, $subscriber))->all(),
        ]);
    }

    public function destroy(Request $request, string $reference, string $deviceId): JsonResponse
    {
        $subscriber = Subscriber::where('reference', $reference)->firstOrFail();
        $device = OfflineDevice::where('subscriber_id', $subscriber->id)->where('device_id', $deviceId)->firstOrFail();

        if ($device->isActive()) {
            $device->update(['status' => 'revoked', 'revoked_at' => now()]);
            $this->record($request, 'device.revoked', "Device revoked for {$reference}", $device);
        }

        return response()->json($this->present($device, $subscriber));
    }

    private function present(OfflineDevice $d, Subscriber $s): array
    {
        return [
            'device_id' => $d->device_id,
            'short_id' => $d->short_id,
            'subscriber_reference' => $s->reference,
            'status' => $d->status,
            'key_version' => $d->key_version,
            'last_counter' => $d->last_counter,
            'last_used_at' => $d->last_used_at?->toIso8601String(),
        ];
    }

    private function assertEnabled(Tenant $tenant): void
    {
        if (! DeviceCodeSettings::for($tenant)->enabled) {
            abort(response()->json(['error' => ['code' => 'device_codes_disabled', 'message' => 'Offline device codes are not enabled for this account. Ask PakaPay to turn them on.']], 403));
        }
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    private function record(Request $request, string $event, string $description, OfflineDevice $device): void
    {
        $this->audit->record($this->tenant($request)->id, $event, 'tenant', null, OfflineDevice::class, $device->id, $description, ['short_id' => $device->short_id], $device->short_id);
    }
}
