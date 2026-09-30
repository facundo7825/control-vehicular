<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('vehiculos:purgar-recorridos')->dailyAt('03:00');

\Illuminate\Support\Facades\Schedule::command('vehiculos:alertar-sin-senal')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('vehiculos:publicar-estados-chofer')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30');
