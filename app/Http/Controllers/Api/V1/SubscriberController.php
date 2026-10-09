<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriberController extends Controller
{
    public function upsert(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:32']]);

        $subscriber = Subscriber::updateOrCreate(['reference' => $reference], $data);

        return response()->json($subscriber->only(['reference', 'phone']), $subscriber->wasRecentlyCreated ? 201 : 200);
    }
}
