<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentCode;
use App\Models\Tenant;
use App\Models\VoiceNumber;
use App\Support\AccountRules;
use App\Services\Codes\CodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodeController extends Controller
{
    public function __construct(private readonly CodeService $codes)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscriber_reference' => ['required', 'string', 'max:191'],
            'merchant_reference' => ['required', 'string', 'max:191'],
            // Optional: lets an unknown merchant be registered as part of this request.
            'merchant' => ['sometimes', 'array'],
            'merchant.name' => ['required_with:merchant', 'string', 'max:191'],
            'merchant.account_number' => array_merge(['required_with:merchant'], array_slice(AccountRules::number(), 1)),
            'merchant.bank_code' => array_merge(['required_with:merchant'], array_slice(AccountRules::bankCode(), 1)),
            'merchant.account_reference' => ['nullable', 'string', 'max:191'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3'],
            // Optional: your own label for the paying account. The funds are held on the subscriber's
            // registered account either way.
            'source_account_reference' => ['nullable', 'string', 'max:191'],
        ], AccountRules::messages('merchant.'));

        [$code, $plain, $merchantCreated] = $this->codes->issue($request->attributes->get('tenant'), $data);

        // The only response that ever carries the code itself.
        return response()->json($this->present($code) + ['code' => $plain, 'merchant_created' => $merchantCreated] + $this->dialing($request->attributes->get('tenant'), $plain), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json($this->present(PaymentCode::where('uuid', $uuid)->firstOrFail()));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        try {
            $code = $this->codes->cancel($request->attributes->get('tenant'), $uuid);
        } catch (\LogicException) {
            return response()->json(['error' => ['code' => 'not_cancellable', 'message' => 'Only an unredeemed code can be cancelled.']], 409);
        }

        return response()->json($this->present($code));
    }

    /**
     * Everything a client needs to let the payer ring and key the code in one go. Pauses (commas)
     * let the call connect before the digits are sent; the trailing # ends the entry. The number
     * is null, with null dial fields, until the operator has set one up for this tenant.
     *
     * @return array{voice_number: ?string, dial_string: ?string, dial_uri: ?string}
     */
    private function dialing(Tenant $tenant, string $plainCode): array
    {
        $number = VoiceNumber::forDialing($tenant)?->e164();

        if ($number === null) {
            return ['voice_number' => null, 'dial_string' => null, 'dial_uri' => null];
        }

        $dial = $number . ',,,' . $plainCode . '#';

        return [
            'voice_number' => $number,
            'dial_string' => $dial,
            // In a tel: link "#" must be percent-encoded or most phones drop it.
            'dial_uri' => 'tel:' . $number . ',,,' . $plainCode . '%23',
        ];
    }

    private function present(PaymentCode $code): array
    {
        return $this->codes->codeData($code) + ['redeemed_at' => $code->redeemed_at?->toIso8601String()];
    }
}
