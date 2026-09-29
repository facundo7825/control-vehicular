<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Identidad\ProveedorIdentidad::class, function ($app) {
            $driver = config('vehiculos.identidad.driver');

            if ($driver === 'simulada' && $app->isProduction()) {
                throw new \LogicException('IDENTIDAD_DRIVER=simulada no está permitido en producción.');
            }

            return match ($driver) {
                'poder_judicial' => new \App\Identidad\EndpointPoderJudicial(),
                default => new \App\Identidad\IdentidadSimulada(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
