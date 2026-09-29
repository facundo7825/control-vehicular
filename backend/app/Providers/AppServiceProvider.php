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

        $this->app->bind(\App\Mapas\ServicioMapas::class, fn () => match (config('vehiculos.mapas.driver')) {
            'google' => new \App\Mapas\GoogleMaps((string) config('vehiculos.mapas.google_api_key')),
            default => new \App\Mapas\ServicioMapasFalso(),
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
