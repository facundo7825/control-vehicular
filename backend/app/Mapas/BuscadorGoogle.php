<?php

namespace App\Mapas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Places API (New), Text Search. */
class BuscadorGoogle implements BuscadorLugares
{
    private const URL = 'https://places.googleapis.com/v1/places:searchText';

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
                'center' => ['latitude' => $lat, 'longitude' => $lng],
                'radius' => (float) self::RADIO_SESGO_M,
            ]];
        }

        try {
            $r = Http::timeout(5)->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.location',
            ])->post(self::URL, $cuerpo);
        } catch (ConnectionException $e) {
            Log::warning('Places sin conexión', ['error' => $e->getMessage()]);

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
}
