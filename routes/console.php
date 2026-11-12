<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('codes:expire')->everyMinute()->withoutOverlapping();
Schedule::command('transactions:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('webhooks:requeue')->everyMinute()->withoutOverlapping();
Schedule::command('platform:check-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('model:prune')->daily();
Schedule::command('audit:verify')->dailyAt('03:00');
// A sign that the scheduler is alive, shown on the System health page.
Schedule::call(fn () => Cache::forever('platform:scheduler:last', now()->timestamp))->everyMinute()->name('scheduler-heartbeat');
