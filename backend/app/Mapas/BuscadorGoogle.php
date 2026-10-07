<?php

namespace App\Mapas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Places API (New), Text Search. Privacidad: la ubicación se redondea a 2 decimales (~1 km) antes de enviarla.
 * La dirección de un punto, con la Geocoding API.
 */
class BuscadorGoogle implements BuscadorLugares, GeocodificadorInverso
{
    private const URL = 'https://places.googleapis.com/v1/places:searchText';

    private const URL_INVERSA = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const RADIO_SESGO_M = 20000;

    public function __construct(private string $apiKey) {}

    public function buscar(string $texto, ?float $lat, ?float $lng): array
    {
        $cuerpo = [
            'textQuery' => trim($texto),
            'languageCode' => 'es',
            'regionCode' => 'AR',
            'maxResultCount' => 5,
        ];
        if ($lat !== null && $lng !== null) {
            $cuerpo['locationBias'] = ['circle' => [
                'center' => ['latitude' => round($lat, 2), 'longitude' => round($lng, 2)],
                'radius' => (float) self::RADIO_SESGO_M,
            ]];
        }

        try {
            $r = Http::timeout(5)->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.location',
            ])->post(self::URL, $cuerpo);
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL o el cuerpo, con el texto buscado.
            Log::warning('Places sin conexión', ['error' => $e::class]);

            return [];
        }

        if ($r->failed()) {
            Log::warning('Places falló', ['http' => $r->status(), 'status' => $r->json('error.status')]);

            return [];
        }

        $lugares = [];
        foreach (array_slice((array) $r->json('places', []), 0, 5) as $p) {
            if (! isset($p['location']['latitude'], $p['location']['longitude'])) {
                continue;
            }
            $direccion = (string) ($p['formattedAddress'] ?? '');
            $lugares[] = [
                'nombre' => (string) ($p['displayName']['text'] ?? $direccion),
                'direccion' => $direccion,
                'lat' => (float) $p['location']['latitude'],
                'lng' => (float) $p['location']['longitude'],
            ];
        }

        return $lugares;
    }

    /** Geocoding API (reverse), en castellano. El punto se redondea a 4 decimales (~11 m) antes de enviarlo. */
    public function direccion(float $lat, float $lng): ?string
    {
        try {
            $r = Http::timeout(2)->get(self::URL_INVERSA, [
                'latlng' => round($lat, 4).','.round($lng, 4),
                'language' => 'es',
                'key' => $this->apiKey,
            ]);
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL, con el punto y la clave.
            Log::warning('Geocoding sin conexión', ['error' => $e::class]);

            return null;
        }

        $estado = $r->json('status');
        if ($r->failed() || ! in_array($estado, ['OK', 'ZERO_RESULTS'], true)) {
            Log::warning('Geocoding falló', ['http' => $r->status(), 'status' => is_string($estado) ? $estado : null]);

            return null;
        }

        $direccion = trim((string) $r->json('results.0.formatted_address', ''));

        return $direccion === '' ? null : mb_substr($direccion, 0, 255);
    }
}
