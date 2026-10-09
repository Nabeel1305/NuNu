<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentCode;
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
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'source_account_reference' => ['required', 'string', 'max:191'],
        ]);

        [$code, $plain] = $this->codes->issue($request->attributes->get('tenant'), $data);

        // The only response that ever carries the code itself.
        return response()->json($this->present($code) + ['code' => $plain], 201);
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

    private function present(PaymentCode $code): array
    {
        return $this->codes->codeData($code) + ['redeemed_at' => $code->redeemed_at?->toIso8601String()];
    }
}
