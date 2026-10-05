<?php

namespace App\Mapas;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pedidos HTTP a un OSRM, compartidos por los recorridos ({@see RutasOsrm}) y los tiempos de viaje
 * ({@see ServicioMapasOsrm}): User-Agent identificable y 3 s de timeout.
 *
 * Solo contra el servidor público de demostración (router.project-osrm.org), cuya política pide como mucho
 * 1 pedido por segundo, un lock serializa los pedidos y, si el anterior fue hace menos de un segundo, se
 * espera lo que falte; si el lock no se consigue en 1 s, esa vez no hay respuesta (sin cortar), para no retener
 * el único worker de `artisan serve` tras un lock colgado. Con un OSRM propio no se limita.
 */
final class ClienteOsrm
{
    public const HOST_PUBLICO = 'router.project-osrm.org';

    public const TIMEOUT_SEG = 3;

    private const CLAVE_LOCK = 'rutas:osrm:lock';

    private const CLAVE_ULTIMO = 'rutas:osrm:ultimo';

    private string $url;

    /** @var Closure(int): void */
    private Closure $dormir;

    /** @param  (Closure(int): void)|null  $dormir  espera en microsegundos; se inyecta en los tests. */
    public function __construct(private string $userAgent, string $url, ?Closure $dormir = null)
    {
        $this->url = rtrim($url, '/');
        $this->dormir = $dormir ?? function (int $micro): void {
            usleep($micro);
        };
    }

    public function esPublico(): bool
    {
        return parse_url($this->url, PHP_URL_HOST) === self::HOST_PUBLICO;
    }

    /**
     * GET {url}/{camino}.
     *
     * @param  Closure(): bool  $enCorte  se consulta justo antes de pedir (tras esperar el lock).
     * @return Response|null null si no se pidió: el corte está activo o el lock del servidor público estaba tomado.
     *
     * @throws ConnectionException
     */
    public function get(string $camino, array $query, Closure $enCorte): ?Response
    {
        $pedir = fn (): Response => Http::timeout(self::TIMEOUT_SEG)->withUserAgent($this->userAgent)
            ->get("{$this->url}/".ltrim($camino, '/'), $query);

        if (! $this->esPublico()) {
            return $enCorte() ? null : $pedir();
        }

        try {
            return Cache::lock(self::CLAVE_LOCK, 10)->block(1, function () use ($pedir, $enCorte) {
                if ($enCorte()) {
                    return null;
                }
                $falta = 1.0 - (microtime(true) - (float) Cache::get(self::CLAVE_ULTIMO, 0.0));
                if ($falta > 0) {
                    ($this->dormir)((int) ceil($falta * 1_000_000));
                }

                try {
                    return $pedir();
                } finally {
                    Cache::put(self::CLAVE_ULTIMO, microtime(true), 60);
                }
            });
        } catch (LockTimeoutException) {
            return null; // Mucha demanda: esta vez sin respuesta, pero el servicio no falló.
        }
    }
}
