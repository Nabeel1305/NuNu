<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use App\Support\AccountRules;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    public function upsert(Request $request, string $reference): JsonResponse
    {
        validator(['reference' => $reference], ['reference' => ['required', 'string', 'max:191']])->validate();

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            // The account the payer's funds are held on and debited from.
            'account_number' => AccountRules::number(),
            'bank_code' => AccountRules::bankCode(),
        ], AccountRules::messages());

        $subscriber = Subscriber::updateOrCreate(['reference' => $reference], $data);

        return response()->json($subscriber->only(['reference', 'phone', 'account_number', 'bank_code']), $subscriber->wasRecentlyCreated ? 201 : 200);
    }
}
