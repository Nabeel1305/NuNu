<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    public function upsert(Request $request, string $reference): JsonResponse
    {
        validator(['reference' => $reference], ['reference' => ['required', 'string', 'max:191']])->validate();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'account_reference' => ['required', 'string', 'max:191'],
        ]);

        $merchant = Merchant::updateOrCreate(['reference' => $reference], $data);

        return response()->json($merchant->only(['reference', 'name', 'account_reference']), $merchant->wasRecentlyCreated ? 201 : 200);
    }
}
