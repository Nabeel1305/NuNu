<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use App\Support\AccountRules;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    public function upsert(Request $request, string $reference): JsonResponse
    {
        validator(['reference' => $reference], ['reference' => ['required', 'string', 'max:191']])->validate();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            // The account credited when a payment is captured.
            'account_number' => AccountRules::number(),
            'bank_code' => AccountRules::bankCode(),
            // Optional: your own label for that account.
            'account_reference' => ['nullable', 'string', 'max:191'],
        ], AccountRules::messages());

        $merchant = Merchant::updateOrCreate(['reference' => $reference], $data);

        return response()->json($merchant->only(['reference', 'name', 'account_number', 'bank_code', 'account_reference']), $merchant->wasRecentlyCreated ? 201 : 200);
    }
}
