<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vehiculos:purgar-recorridos')->dailyAt('03:00');

Schedule::command('vehiculos:purgar-fichajes')->dailyAt('03:15');

Schedule::command('vehiculos:alertar-sin-senal')->everyMinute()->withoutOverlapping();

Schedule::command('vehiculos:publicar-estados-chofer')->everyMinute()->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30');
