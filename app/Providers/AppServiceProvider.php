<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Per tenant, so one busy partner cannot starve another.
        RateLimiter::for('tenant-api', fn (Request $request) => Limit::perMinute((int) config('platform.api.per_minute'))
            ->by('tenant:' . ($request->attributes->get('tenant')?->id ?? $request->ip())));
    }
}
