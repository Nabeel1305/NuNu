<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Codes\CodeService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function show(CodeService $codes, string $uuid): JsonResponse
    {
        $transaction = Transaction::with('paymentCode')->where('uuid', $uuid)->firstOrFail();

        return response()->json($codes->transactionData($transaction, $transaction->paymentCode));
    }
}
