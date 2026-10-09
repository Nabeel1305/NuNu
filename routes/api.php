<?php

use App\Http\Controllers\Api\V1\CodeController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\SubscriberController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Controllers\VoiceController;
use App\Http\Middleware\AuthenticateTenant;
use App\Http\Middleware\Idempotent;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateTenant::class, 'throttle:tenant-api'])->group(function () {
    Route::post('codes', [CodeController::class, 'store'])->middleware(Idempotent::class);
    Route::get('codes/{uuid}', [CodeController::class, 'show']);
    Route::post('codes/{uuid}/cancel', [CodeController::class, 'cancel'])->middleware(Idempotent::class);

    Route::get('transactions/{uuid}', [TransactionController::class, 'show']);

    Route::put('merchants/{reference}', [MerchantController::class, 'upsert']);
    Route::put('subscribers/{reference}', [SubscriberController::class, 'upsert']);

    Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store']);
});

// Voice provider callbacks: no API key; each number carries its own secret token.
Route::post('voice/africastalking', [VoiceController::class, 'africastalking'])->name('voice.africastalking');
