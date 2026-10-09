<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\TenantAccessController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantUserController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Portal;
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

        Route::post('tenants/{tenant}/portal-users', [TenantUserController::class, 'store'])->name('portal-users.store');
        Route::post('tenants/{tenant}/portal-users/{user}/access-link', [TenantUserController::class, 'reinvite'])->name('portal-users.reinvite');
        Route::post('tenants/{tenant}/portal-users/{user}/reset-2fa', [TenantUserController::class, 'resetTwoFactor'])->name('portal-users.reset-2fa');
        Route::patch('tenants/{tenant}/portal-users/{user}', [TenantUserController::class, 'toggle'])->name('portal-users.toggle');
    });
});

// ── Tenant portal ────────────────────────────────────────────────────────────
// Staff of a tenant. Every route behind portal.context runs with the tenant pinned, so the
// tenant-scoped models can be used directly; a role decides which parts a person may open.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('login', [Portal\AuthController::class, 'show'])->name('login');
    Route::post('login', [Portal\AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('two-factor', [Portal\AuthController::class, 'challenge'])->name('two-factor');
    Route::post('two-factor', [Portal\AuthController::class, 'verify'])->name('two-factor.verify')->middleware('throttle:20,1');
    Route::get('invite/{token}', [Portal\AuthController::class, 'invitation'])->name('invitation')->middleware('throttle:20,1');
    Route::post('invite/{token}', [Portal\AuthController::class, 'accept'])->name('invitation.accept')->middleware('throttle:10,1');

    Route::middleware(['auth:tenant', 'portal.context'])->group(function () {
        Route::post('logout', [Portal\AuthController::class, 'logout'])->name('logout');
        Route::get('account', [Portal\AccountController::class, 'show'])->name('account');
        Route::post('account/two-factor', [Portal\AccountController::class, 'confirmTwoFactor'])->name('account.two-factor');
        Route::post('account/password', [Portal\AccountController::class, 'updatePassword'])->name('account.password');

        Route::middleware('portal.2fa')->group(function () {
            Route::get('/', Portal\DashboardController::class)->name('dashboard');
            Route::get('settings', Portal\SettingsController::class)->name('settings');

            Route::middleware('portal.can:money')->group(function () {
                Route::get('transactions', [Portal\TransactionsController::class, 'index'])->name('transactions.index');
                Route::get('transactions/export', [Portal\TransactionsController::class, 'export'])->name('transactions.export');
                Route::get('transactions/{uuid}', [Portal\TransactionsController::class, 'show'])->whereUuid('uuid')->name('transactions.show');
                Route::get('codes', [Portal\CodesController::class, 'index'])->name('codes.index');
                Route::get('codes/{uuid}', [Portal\CodesController::class, 'show'])->whereUuid('uuid')->name('codes.show');
                Route::get('subscribers', [Portal\PartiesController::class, 'subscribers'])->name('subscribers');
                Route::get('merchants', [Portal\PartiesController::class, 'merchants'])->name('merchants');
            });
            Route::post('codes/{uuid}/cancel', [Portal\CodesController::class, 'cancel'])->whereUuid('uuid')->name('codes.cancel')->middleware('portal.can:cancel_code');

            Route::middleware('portal.can:developer_view')->group(function () {
                Route::get('developer/keys', [Portal\DeveloperController::class, 'keys'])->name('keys');
                Route::get('developer/webhooks', [Portal\DeveloperController::class, 'webhooks'])->name('webhooks');
                Route::get('developer/deliveries', [Portal\DeveloperController::class, 'deliveries'])->name('deliveries');
                Route::get('developer/deliveries/{delivery}', [Portal\DeveloperController::class, 'delivery'])->whereNumber('delivery')->name('deliveries.show');
            });
            Route::middleware('portal.can:manage_keys')->group(function () {
                Route::post('developer/keys', [Portal\DeveloperController::class, 'issueKey'])->name('keys.store');
                Route::delete('developer/keys/{key}', [Portal\DeveloperController::class, 'revokeKey'])->whereNumber('key')->name('keys.destroy');
            });
            Route::middleware('portal.can:manage_webhooks')->group(function () {
                Route::post('developer/webhooks', [Portal\DeveloperController::class, 'storeWebhook'])->name('webhooks.store');
                Route::put('developer/webhooks/{endpoint}', [Portal\DeveloperController::class, 'updateWebhook'])->whereNumber('endpoint')->name('webhooks.update');
                Route::patch('developer/webhooks/{endpoint}', [Portal\DeveloperController::class, 'toggleWebhook'])->whereNumber('endpoint')->name('webhooks.toggle');
                Route::post('developer/webhooks/{endpoint}/secret', [Portal\DeveloperController::class, 'rotateWebhookSecret'])->whereNumber('endpoint')->name('webhooks.secret');
                Route::post('developer/webhooks/{endpoint}/test', [Portal\DeveloperController::class, 'testWebhook'])->whereNumber('endpoint')->name('webhooks.test');
                Route::delete('developer/webhooks/{endpoint}', [Portal\DeveloperController::class, 'destroyWebhook'])->whereNumber('endpoint')->name('webhooks.destroy');
                Route::post('developer/deliveries/{delivery}/retry', [Portal\DeveloperController::class, 'retry'])->whereNumber('delivery')->name('deliveries.retry');
            });

            Route::middleware('portal.can:audit')->group(function () {
                Route::get('audit', [Portal\AuditController::class, 'index'])->name('audit');
                Route::post('audit/verify', [Portal\AuditController::class, 'verify'])->name('audit.verify');
            });

            Route::middleware('portal.can:team')->prefix('team')->group(function () {
                Route::get('/', [Portal\TeamController::class, 'index'])->name('team');
                Route::post('/', [Portal\TeamController::class, 'store'])->name('team.store');
                Route::patch('{user}/role', [Portal\TeamController::class, 'update'])->whereNumber('user')->name('team.role');
                Route::patch('{user}', [Portal\TeamController::class, 'toggle'])->whereNumber('user')->name('team.toggle');
                Route::post('{user}/access-link', [Portal\TeamController::class, 'reinvite'])->whereNumber('user')->name('team.access-link');
                Route::post('{user}/reset-2fa', [Portal\TeamController::class, 'resetTwoFactor'])->whereNumber('user')->name('team.reset-2fa');
                Route::delete('{user}', [Portal\TeamController::class, 'destroy'])->whereNumber('user')->name('team.destroy');
            });
        });
    });
});
