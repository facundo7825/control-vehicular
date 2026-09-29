<?php

namespace App\Mapas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMaps implements ServicioMapas
{
    private const URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    public function __construct(private string $apiKey) {}

    public function duracionesHacia(array $origenes, float $lat, float $lng): array
    {
        if ($origenes === []) {
            return [];
        }

        $claves = array_keys($origenes);
        $nulos = array_fill_keys($claves, null);

        try {
            $r = Http::timeout(5)->get(self::URL, [
                'origins' => implode('|', array_map(fn (array $o) => "{$o[0]},{$o[1]}", $origenes)),
                'destinations' => "$lat,$lng",
                'mode' => 'driving',
                'departure_time' => 'now',
                'key' => $this->apiKey,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Distance Matrix sin conexión', ['error' => $e->getMessage()]);

            return $nulos;
        }

        if ($r->failed() || $r->json('status') !== 'OK') {
            Log::warning('Distance Matrix falló', ['http' => $r->status(), 'status' => $r->json('status')]);

            return $nulos;
        }

        $resultado = [];
        foreach ($claves as $i => $clave) {
            $el = $r->json("rows.$i.elements.0");
            $resultado[$clave] = ($el['status'] ?? null) === 'OK'
                ? (int) ($el['duration_in_traffic']['value'] ?? $el['duration']['value'])
                : null;
        }

        return $resultado;
    }

    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
    {
        return $this->duracionesHacia(['r' => [$oLat, $oLng]], $dLat, $dLng)['r'];
    }
}
