<?php

namespace App\Mapas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda de direcciones con la API Georef del Estado argentino (datos.gob.ar), gratuita y sin clave.
 *
 * Usa `GET {url}/direcciones`, acotado a la provincia configurada. En Catamarca no tiene alturas, pero sí
 * resuelve intersecciones ("Sarmiento y Rivadavia"), a veces en varias localidades. `/calles` no se usa: no
 * devuelve una ubicación. Las direcciones sin ubicación se descartan.
 *
 * Privacidad: Georef no recibe la ubicación, solo el texto y la provincia; con lat/lng los resultados se ordenan
 * acá por cercanía. Las consultas se cachean 24 h por texto normalizado. Si un pedido falla, durante 60 s todas
 * las búsquedas devuelven [] al instante. Nunca lanza y el log no lleva el texto, la URL ni coordenadas.
 */
class BuscadorGeoref implements BuscadorLugares
{
    private const CLAVE_CORTE = 'lugares:georef:corte';

    private const SEGUNDOS_CACHE = 86400;

    private const SEGUNDOS_CORTE = 60;

    private const TIMEOUT_SEG = 3;

    /** Se piden más de 5 para poder elegir los más cercanos. */
    private const MAXIMO_PEDIDO = 10;

    /** Palabras que van en minúscula salvo al principio del nombre. */
    private const MINUSCULAS = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en', 'a'];

    private string $url;

    public function __construct(string $url, private ?string $provincia = null)
    {
        $this->url = rtrim($url, '/');
        $this->provincia = trim((string) $provincia) ?: null;
    }

    public function buscar(string $texto, ?float $lat, ?float $lng): array
    {
        $texto = trim($texto);
        $clave = 'lugares:georef:'.md5(mb_strtolower($texto).'|'.mb_strtolower((string) $this->provincia).'|'.$this->url);

        $lugares = Cache::get($clave);
        if (! is_array($lugares)) {
            if (Cache::has(self::CLAVE_CORTE)) {
                return [];
            }
            $lugares = $this->consultar($texto);
            if ($lugares === null) {
                return [];
            }
            Cache::put($clave, $lugares, self::SEGUNDOS_CACHE);
        }

        if ($lat !== null && $lng !== null) {
            usort($lugares, fn (array $a, array $b) => Distancia::metros($lat, $lng, $a['lat'], $a['lng'])
                <=> Distancia::metros($lat, $lng, $b['lat'], $b['lng']));
        }

        return array_slice($lugares, 0, 5);
    }

    /** @return ?list<array{nombre: string, direccion: string, lat: float, lng: float}> null si falló. */
    private function consultar(string $texto): ?array
    {
        $params = ['direccion' => $texto, 'max' => self::MAXIMO_PEDIDO];
        if ($this->provincia !== null) {
            $params['provincia'] = $this->provincia;
        }

        try {
            $r = Http::timeout(self::TIMEOUT_SEG)->acceptJson()->get($this->url.'/direcciones', $params);
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL, con el texto buscado.
            Log::warning('Georef sin conexión', ['error' => $e::class]);
            $this->cortar();

            return null;
        } catch (\Throwable $e) {
            Log::warning('Georef: no se pudo consultar', ['error' => $e::class]);
            $this->cortar();

            return null;
        }

        $direcciones = $r->successful() ? $r->json('direcciones') : null;
        if (! is_array($direcciones)) {
            Log::warning('Georef falló', ['http' => $r->status()]);
            $this->cortar();

            return null;
        }

        return $this->mapear($direcciones);
    }

    private function cortar(): void
    {
        Cache::put(self::CLAVE_CORTE, true, self::SEGUNDOS_CORTE);
    }

    /** @return list<array{nombre: string, direccion: string, lat: float, lng: float}> */
    private function mapear(array $filas): array
    {
        $lugares = [];
        foreach ($filas as $f) {
            $lat = $f['ubicacion']['lat'] ?? null;
            $lng = $f['ubicacion']['lon'] ?? null;
            $calle = trim((string) ($f['calle']['nombre'] ?? ''));
            if (! is_numeric($lat) || ! is_numeric($lng) || $calle === '') {
                continue;
            }

            $nombre = self::legible($calle);
            $altura = $f['altura']['valor'] ?? null;
            if (is_numeric($altura) && (int) $altura > 0) {
                $nombre .= ' '.(int) $altura;
            }
            $cruce = trim((string) ($f['calle_cruce_1']['nombre'] ?? ''));
            if ($cruce !== '') {
                $nombre .= ' y '.self::legible($cruce);
            }
            $localidad = trim((string) ($f['localidad_censal']['nombre'] ?? $f['departamento']['nombre'] ?? ''));
            if ($localidad !== '') {
                $nombre .= ', '.self::legible($localidad);
            }

            $nomenclatura = trim((string) ($f['nomenclatura'] ?? ''));

            $lugares[] = [
                'nombre' => $nombre,
                'direccion' => $nomenclatura !== '' ? self::legible($nomenclatura) : $nombre,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        }

        return $lugares;
    }

    /** "AV. PTE. JUAN DOMINGO PERON" → "Av. Pte. Juan Domingo Peron"; respeta conectores y números romanos. */
    private static function legible(string $texto): string
    {
        $palabras = explode(' ', mb_convert_case(mb_strtolower($texto), MB_CASE_TITLE));
        foreach ($palabras as $i => $p) {
            $minuscula = mb_strtolower($p);
            $inicio = $i === 0 || str_ends_with($palabras[$i - 1], ',');
            if (! $inicio && in_array($minuscula, self::MINUSCULAS, true)) {
                $palabras[$i] = $minuscula;
            } elseif (preg_match('/^[ivxl]{2,}[.,]?$/', $minuscula) === 1) {
                $palabras[$i] = mb_strtoupper($p);
            }
        }

        return implode(' ', $palabras);
    }
}
