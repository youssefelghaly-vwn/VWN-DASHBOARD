<?php

use Illuminate\Support\Facades\Schedule;

// Keep every connected integration's local data fresh, out of the request
// cycle. Runs every 5 minutes; withoutOverlapping() means a slow run never
// stacks on top of itself. The command queues one job per integration by
// default — see docs/AUTO_SYNC_HOSTINGER.md for the cron + queue setup.
Schedule::command('integrations:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
