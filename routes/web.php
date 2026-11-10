<?php

use App\Http\Controllers\Admin;
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

    // An operator's own pages: reachable before two-factor is set up, and while a temporary password is being replaced.
    Route::middleware(['auth:admin', 'admin.session', 'admin.record'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('security', [TwoFactorController::class, 'setup'])->name('security');
        Route::post('security', [TwoFactorController::class, 'confirm'])->name('security.confirm');
        Route::get('password', [Admin\AccountController::class, 'password'])->name('password');
        Route::put('password', [Admin\AccountController::class, 'updatePassword'])->name('password.update');
        Route::post('sign-out-everywhere', [Admin\AccountController::class, 'signOutEverywhere'])->name('sign-out-everywhere');
    });

    Route::middleware(['auth:admin', 'admin.session', EnsureTwoFactor::class, 'admin.record'])->group(function () {

        Route::redirect('/', '/admin/tenants');

        // ── Look (every role) ────────────────────────────────────────────────
        Route::middleware('admin.can:view')->group(function () {
            Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
            Route::get('search', [Admin\SearchController::class, 'index'])->name('search');
            Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
            Route::get('payments', [Admin\PaymentsController::class, 'index'])->name('payments.index');
            Route::get('payments/{uuid}', [Admin\PaymentsController::class, 'show'])->whereUuid('uuid')->name('payments.show');
            Route::get('payers', [Admin\PartiesController::class, 'subscribers'])->name('parties.subscribers');
            Route::get('recipients', [Admin\PartiesController::class, 'merchants'])->name('parties.merchants');
            Route::get('phones', [Admin\DevicesController::class, 'index'])->name('devices.index');
            Route::get('webhooks', [Admin\WebhooksController::class, 'endpoints'])->name('endpoints.index');
            Route::get('deliveries', [Admin\WebhooksController::class, 'deliveries'])->name('deliveries.index');
            Route::get('deliveries/{delivery}', [Admin\WebhooksController::class, 'delivery'])->whereNumber('delivery')->name('deliveries.show');
            Route::get('voice-numbers', [Admin\VoiceNumbersController::class, 'index'])->name('voice.index');
            Route::get('calls', Admin\CallsController::class)->name('calls');
            Route::get('health', [Admin\HealthController::class, 'index'])->name('health');
            Route::get('changelog', Admin\ChangelogController::class)->name('changelog');
        });

        Route::middleware('admin.can:reports')->group(function () {
            Route::get('payments-export', [Admin\PaymentsController::class, 'export'])->name('payments.export');
            Route::get('phones-export', [Admin\DevicesController::class, 'export'])->name('devices.export');
        });

        Route::middleware('admin.can:audit')->group(function () {
            Route::get('audit', [Admin\AuditController::class, 'index'])->name('audit');
            Route::get('audit-export', [Admin\AuditController::class, 'export'])->name('audit.export');
            Route::post('tenants/{tenant}/verify-audit', [TenantController::class, 'verifyAudit'])->name('tenants.verify-audit');
            Route::post('health/verify-audit', [Admin\HealthController::class, 'verifyAudit'])->name('health.verify-audit');
        });

        // ── Fix things (support and owner) ───────────────────────────────────
        Route::middleware('admin.can:payments')->group(function () {
            Route::post('payments/{uuid}/recheck', [Admin\PaymentsController::class, 'recheck'])->whereUuid('uuid')->name('payments.recheck');
            Route::post('payments/{uuid}/mark-failed', [Admin\PaymentsController::class, 'markFailed'])->whereUuid('uuid')->name('payments.mark-failed');
            Route::post('codes/{uuid}/cancel', [Admin\PaymentsController::class, 'cancelCode'])->whereUuid('uuid')->name('codes.cancel');
        });
        Route::middleware('admin.can:webhooks')->group(function () {
            Route::post('deliveries/{delivery}/retry', [Admin\WebhooksController::class, 'retry'])->whereNumber('delivery')->name('deliveries.retry');
            Route::post('webhooks/{endpoint}/test', [Admin\WebhooksController::class, 'test'])->whereNumber('endpoint')->name('endpoints.test');
            Route::patch('webhooks/{endpoint}', [Admin\WebhooksController::class, 'toggle'])->whereNumber('endpoint')->name('endpoints.toggle');
            Route::post('webhooks/{endpoint}/secret', [Admin\WebhooksController::class, 'rotate'])->whereNumber('endpoint')->name('endpoints.rotate');
        });
        Route::post('phones/{device}/revoke', [Admin\DevicesController::class, 'revoke'])->whereNumber('device')->name('devices.revoke')->middleware('admin.can:devices');

        Route::middleware('admin.can:portal_users')->group(function () {
            Route::post('tenants/{tenant}/portal-users', [TenantUserController::class, 'store'])->name('portal-users.store');
            Route::post('tenants/{tenant}/portal-users/{user}/access-link', [TenantUserController::class, 'reinvite'])->name('portal-users.reinvite');
            Route::post('tenants/{tenant}/portal-users/{user}/reset-2fa', [TenantUserController::class, 'resetTwoFactor'])->name('portal-users.reset-2fa');
            Route::patch('tenants/{tenant}/portal-users/{user}', [TenantUserController::class, 'toggle'])->name('portal-users.toggle');
            Route::patch('tenants/{tenant}/portal-users/{user}/role', [TenantUserController::class, 'role'])->name('portal-users.role');
            Route::delete('tenants/{tenant}/portal-users/{user}', [TenantUserController::class, 'destroy'])->name('portal-users.destroy');
        });
        Route::post('tenants/{tenant}/portal-users/{user}/impersonate', [TenantUserController::class, 'impersonate'])->name('portal-users.impersonate')->middleware(['admin.can:view_as', 'throttle:10,1']);

        // ── Change tenants (owner) ───────────────────────────────────────────
        Route::middleware('admin.can:tenants')->group(function () {
            Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
            Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
            Route::post('tenants/{tenant}/archive', [TenantController::class, 'archive'])->name('tenants.archive');
            Route::post('tenants/{tenant}/restore', [TenantController::class, 'restore'])->name('tenants.restore');
            Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
            Route::post('tenants/{tenant}/portal-users-bulk', [TenantController::class, 'bulkUsers'])->name('tenants.bulk-users');

            Route::post('tenants/{tenant}/keys', [TenantAccessController::class, 'issueKey'])->name('keys.store');
            Route::delete('tenants/{tenant}/keys/{key}', [TenantAccessController::class, 'revokeKey'])->name('keys.destroy');
            Route::patch('tenants/{tenant}/keys/{key}', [TenantAccessController::class, 'updateKey'])->name('keys.update');
            Route::post('tenants/{tenant}/keys/{key}/rotate', [TenantAccessController::class, 'rotateKey'])->name('keys.rotate');
            Route::post('tenants/{tenant}/keys-revoke-all', [TenantAccessController::class, 'revokeAllKeys'])->name('keys.revoke-all');
            Route::post('tenants/{tenant}/numbers', [TenantAccessController::class, 'addNumber'])->name('numbers.store');
            Route::patch('tenants/{tenant}/numbers/{number}', [TenantAccessController::class, 'toggleNumber'])->name('numbers.toggle');
            Route::post('tenants/{tenant}/numbers/{number}/rotate-token', [TenantAccessController::class, 'rotateNumberToken'])->name('numbers.rotate');
            Route::post('tenants/{tenant}/numbers/{number}/reassign', [TenantAccessController::class, 'reassignNumber'])->name('numbers.reassign');
            Route::delete('tenants/{tenant}/numbers/{number}', [TenantAccessController::class, 'destroyNumber'])->name('numbers.destroy');
        });

        Route::middleware('admin.can:tenants')->prefix('voice-numbers')->name('voice.')->group(function () {
            Route::post('/', [Admin\VoiceNumbersController::class, 'store'])->name('store');
            Route::put('{number}', [Admin\VoiceNumbersController::class, 'update'])->whereNumber('number')->name('update');
            Route::patch('{number}', [Admin\VoiceNumbersController::class, 'toggle'])->whereNumber('number')->name('toggle');
            Route::post('{number}/rotate-token', [Admin\VoiceNumbersController::class, 'rotate'])->whereNumber('number')->name('rotate');
            Route::post('{number}/reassign', [Admin\VoiceNumbersController::class, 'reassign'])->whereNumber('number')->name('reassign');
            Route::delete('{number}', [Admin\VoiceNumbersController::class, 'destroy'])->whereNumber('number')->name('destroy');
        });

        // ── Run the platform (owner) ─────────────────────────────────────────
        Route::middleware('admin.can:operators')->prefix('operators')->name('operators.')->group(function () {
            Route::get('/', [Admin\OperatorController::class, 'index'])->name('index');
            Route::post('/', [Admin\OperatorController::class, 'store'])->name('store');
            Route::patch('{admin}/role', [Admin\OperatorController::class, 'role'])->name('role');
            Route::patch('{admin}', [Admin\OperatorController::class, 'toggle'])->name('toggle');
            Route::post('{admin}/reset-2fa', [Admin\OperatorController::class, 'resetTwoFactor'])->name('reset-2fa');
            Route::post('{admin}/reset-password', [Admin\OperatorController::class, 'resetPassword'])->name('reset-password');
            Route::delete('{admin}', [Admin\OperatorController::class, 'destroy'])->name('destroy');
        });
        Route::middleware('admin.can:settings')->group(function () {
            Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings');
            Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        });
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

    Route::middleware(['auth:tenant', 'portal.context', 'portal.impersonation'])->group(function () {
        Route::post('logout', [Portal\AuthController::class, 'logout'])->name('logout');
        Route::post('impersonation/stop', [Portal\ImpersonationController::class, 'stop'])->name('impersonation.stop');
        Route::get('account', [Portal\AccountController::class, 'show'])->name('account');
        Route::post('account/two-factor', [Portal\AccountController::class, 'confirmTwoFactor'])->name('account.two-factor');
        Route::post('account/password', [Portal\AccountController::class, 'updatePassword'])->name('account.password');

        Route::middleware('portal.2fa')->group(function () {
            Route::get('/', Portal\DashboardController::class)->name('dashboard');
            Route::get('settings', Portal\SettingsController::class)->name('settings');
            Route::put('settings/direct-codes', [Portal\SettingsController::class, 'updateDirectCodes'])->name('settings.direct-codes')->middleware('portal.can:manage_settings');

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
