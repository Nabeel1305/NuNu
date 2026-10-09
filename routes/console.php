<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('codes:expire')->everyMinute()->withoutOverlapping();
Schedule::command('transactions:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('model:prune')->daily();
