<?php

namespace App\Providers;

use App\Identidad\EndpointPoderJudicial;
use App\Identidad\IdentidadSimulada;
use App\Identidad\ProveedorIdentidad;
use App\Mapas\BuscadorCombinado;
use App\Mapas\BuscadorFalso;
use App\Mapas\BuscadorGeoref;
use App\Mapas\BuscadorGoogle;
use App\Mapas\BuscadorLugares;
use App\Mapas\BuscadorNominatim;
use App\Mapas\GoogleMaps;
use App\Mapas\RutasFalso;
use App\Mapas\RutasGoogle;
use App\Mapas\RutasOsrm;
use App\Mapas\ServicioMapas;
use App\Mapas\ServicioMapasFalso;
use App\Mapas\ServicioMapasOsrm;
use App\Mapas\ServicioRutas;
use App\Notificaciones\Notificador;
use App\Notificaciones\NotificadorFcm;
use App\Notificaciones\NotificadorRegistro;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProveedorIdentidad::class, function ($app) {
            $driver = config('vehiculos.identidad.driver');

            if ($driver === 'simulada' && $app->isProduction()) {
                throw new \LogicException('IDENTIDAD_DRIVER=simulada no está permitido en producción.');
            }

            return match ($driver) {
                'poder_judicial' => new EndpointPoderJudicial,
                default => new IdentidadSimulada,
            };
        });

        $this->app->bind(ServicioMapas::class, fn () => match (config('vehiculos.mapas.driver')) {
            'google' => new GoogleMaps((string) config('vehiculos.mapas.google_api_key')),
            'osrm' => new ServicioMapasOsrm((string) config('vehiculos.rutas.user_agent'), (string) config('vehiculos.rutas.osrm_url')),
            default => new ServicioMapasFalso,
        });

        // LUGARES_DRIVER admite una lista ("nominatim,georef"): se consultan en orden y se unen los resultados.
        $this->app->bind(BuscadorLugares::class, function () {
            $buscadores = array_map(fn (string $driver) => match ($driver) {
                'google' => new BuscadorGoogle((string) config('vehiculos.mapas.google_api_key')),
                'falso' => new BuscadorFalso,
                'georef' => new BuscadorGeoref((string) config('vehiculos.lugares.georef_url'), config('vehiculos.lugares.provincia')),
                default => new BuscadorNominatim(
                    (string) config('vehiculos.lugares.user_agent'), null, (string) config('vehiculos.lugares.nominatim_url'),
                ),
            }, BuscadorCombinado::drivers((string) config('vehiculos.lugares.driver')));

            return count($buscadores) === 1 ? $buscadores[0] : new BuscadorCombinado($buscadores);
        });

        $this->app->bind(ServicioRutas::class, fn () => match (config('vehiculos.rutas.driver')) {
            'google' => new RutasGoogle((string) config('vehiculos.mapas.google_api_key')),
            'falso' => new RutasFalso,
            default => new RutasOsrm((string) config('vehiculos.rutas.user_agent'), (string) config('vehiculos.rutas.osrm_url')),
        });

        $this->app->bind(Notificador::class, fn ($app) => match (config('vehiculos.notificaciones.driver')) {
            'fcm' => new NotificadorFcm($app->make(Messaging::class)),
            default => new NotificadorRegistro,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Búsqueda de lugares: 30 por minuto y por usuario, para no agotar el servicio externo.
        RateLimiter::for('lugares', fn (Request $request) => Limit::perMinute(30)
            ->by((string) $request->user()?->id ?: $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => 'Demasiadas búsquedas. Probá de nuevo en un minuto.'], 429, $headers,
            )));

        // Recorridos: 60 por minuto y por usuario (la app recalcula como mucho cada 30 s).
        RateLimiter::for('rutas', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->user()?->id ?: $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => 'Demasiados pedidos de recorrido. Probá de nuevo en un minuto.'], 429, $headers,
            )));
    }
}
