<?php

use App\Jobs\SyncGhlJob;
use App\Models\GhlConnection;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    if ($c = GhlConnection::current()) {
        SyncGhlJob::dispatch($c->id);
    }
})->everyFifteenMinutes();
