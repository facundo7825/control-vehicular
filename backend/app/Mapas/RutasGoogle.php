<?php

namespace App\Mapas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Recorridos con Directions API en castellano. Las indicaciones vienen de `html_instructions`, sin etiquetas. */
class RutasGoogle extends RutasRemotas
{
    private const URL = 'https://maps.googleapis.com/maps/api/directions/json';

    /** Maniobra de Google => tipo para la app (los mismos que {@see InstruccionesOsrm::tipo()}). */
    private const TIPOS = [
        'turn-right' => 'derecha', 'turn-left' => 'izquierda',
        'turn-slight-right' => 'leve_derecha', 'turn-slight-left' => 'leve_izquierda',
        'turn-sharp-right' => 'cerrado_derecha', 'turn-sharp-left' => 'cerrado_izquierda',
        'uturn-right' => 'retorno', 'uturn-left' => 'retorno',
        'roundabout-right' => 'rotonda', 'roundabout-left' => 'rotonda',
        'ramp-right' => 'leve_derecha', 'ramp-left' => 'leve_izquierda',
        'fork-right' => 'leve_derecha', 'fork-left' => 'leve_izquierda',
        'keep-right' => 'leve_derecha', 'keep-left' => 'leve_izquierda',
    ];

    public function __construct(private string $apiKey) {}

    protected function nombre(): string
    {
        return 'google';
    }

    protected function consultar(float $oLat, float $oLng, float $dLat, float $dLng): array|false|null
    {
        try {
            $r = Http::timeout(self::TIMEOUT_SEG)->get(self::URL, [
                'origin' => "$oLat,$oLng",
                'destination' => "$dLat,$dLng",
                'mode' => 'driving',
                'language' => 'es',
                'key' => $this->apiKey,
            ]);
        } catch (ConnectionException $e) {
            // Nunca el mensaje: lleva la URL, con coordenadas y la clave.
            Log::warning('Directions sin conexión', ['error' => $e::class]);

            return false;
        }

        if (in_array($r->json('status'), ['ZERO_RESULTS', 'NOT_FOUND'], true)) {
            return null;
        }
        if ($r->failed() || $r->json('status') !== 'OK' || ! is_array($r->json('routes.0.legs.0'))) {
            Log::warning('Directions falló', ['http' => $r->status(), 'status' => $r->json('status')]);

            return false;
        }

        return $this->mapear($r->json('routes.0.legs.0'));
    }

    private function mapear(array $tramo): array
    {
        $puntos = [];
        $pasos = [];
        foreach ($tramo['steps'] as $i => $paso) {
            // Cada paso trae su polilínea; se unen sin repetir el punto en común. El paso empieza en su primer punto.
            $linea = self::decodificar((string) $paso['polyline']['points']);
            if ($puntos !== [] && $linea !== [] && end($puntos) === $linea[0]) {
                array_shift($linea);
                $indice = count($puntos) - 1;
            } else {
                $indice = count($puntos);
            }
            array_push($puntos, ...$linea);

            $pasos[] = [
                'instruccion' => self::sinHtml((string) $paso['html_instructions']),
                'distancia_m' => (int) $paso['distance']['value'],
                'indice' => min($indice, max(0, count($puntos) - 1)),
                'lat' => round((float) $paso['start_location']['lat'], 6),
                'lng' => round((float) $paso['start_location']['lng'], 6),
                'tipo' => $i === 0 ? 'salida' : (self::TIPOS[$paso['maneuver'] ?? ''] ?? 'recto'),
            ];
        }

        // Directions no tiene un paso de llegada: se agrega para que la app lo trate igual que con OSRM.
        $fin = end($tramo['steps']);
        $pasos[] = [
            'instruccion' => 'Llegaste a destino',
            'distancia_m' => 0,
            'indice' => max(0, count($puntos) - 1),
            'lat' => round((float) $fin['end_location']['lat'], 6),
            'lng' => round((float) $fin['end_location']['lng'], 6),
            'tipo' => 'llegada',
        ];

        return [
            'distancia_m' => (int) $tramo['distance']['value'],
            'duracion_s' => (int) $tramo['duration']['value'],
            'puntos' => $puntos,
            'pasos' => $pasos,
        ];
    }

    /** "Gira a la <b>derecha</b><div>El destino está a la derecha.</div>" => "Gira a la derecha. El destino está a la derecha". */
    private static function sinHtml(string $html): string
    {
        $texto = (string) preg_replace('/<div[^>]*>/i', '. ', $html);
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $texto);
        $texto = (string) preg_replace('/\s*(\.\s*)+/u', '. ', $texto);

        return rtrim(trim($texto), '.');
    }

    /** @return list<array{0: float, 1: float}> puntos [lat, lng] de una polilínea codificada de Google. */
    private static function decodificar(string $codigo): array
    {
        $puntos = [];
        $valores = [0, 0];
        $i = 0;
        $largo = strlen($codigo);
        while ($i < $largo) {
            foreach ([0, 1] as $k) {
                $resultado = 0;
                $corrimiento = 0;
                do {
                    $b = ord($codigo[$i++]) - 63;
                    $resultado |= ($b & 0x1F) << $corrimiento;
                    $corrimiento += 5;
                } while ($b >= 0x20 && $i < $largo);
                $valores[$k] += ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);
            }
            $puntos[] = [round($valores[0] / 1e5, 6), round($valores[1] / 1e5, 6)];
        }

        return $puntos;
    }
}
