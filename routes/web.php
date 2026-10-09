<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\TenantAccessController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Middleware\EnsureTwoFactor;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login']);

    Route::get('two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor');
    Route::post('two-factor', [TwoFactorController::class, 'verify'])->name('two-factor.verify');

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('security', [TwoFactorController::class, 'setup'])->name('security');
        Route::post('security', [TwoFactorController::class, 'confirm'])->name('security.confirm');
    });

    Route::middleware(['auth:admin', EnsureTwoFactor::class])->group(function () {

        Route::redirect('/', '/admin/tenants');
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::post('tenants/{tenant}/verify-audit', [TenantController::class, 'verifyAudit'])->name('tenants.verify-audit');

        Route::post('tenants/{tenant}/keys', [TenantAccessController::class, 'issueKey'])->name('keys.store');
        Route::delete('tenants/{tenant}/keys/{key}', [TenantAccessController::class, 'revokeKey'])->name('keys.destroy');
        Route::post('tenants/{tenant}/numbers', [TenantAccessController::class, 'addNumber'])->name('numbers.store');
        Route::patch('tenants/{tenant}/numbers/{number}', [TenantAccessController::class, 'toggleNumber'])->name('numbers.toggle');
        Route::post('tenants/{tenant}/numbers/{number}/rotate-token', [TenantAccessController::class, 'rotateNumberToken'])->name('numbers.rotate');
    });
});
