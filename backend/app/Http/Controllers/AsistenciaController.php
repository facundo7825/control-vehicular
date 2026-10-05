<?php

namespace App\Http\Controllers;

use App\Servicios\ServicioAsistencia;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/** POST /api/asistencia/eventos: un fichaje o un lote {"eventos": [...]} de hasta 100. */
class AsistenciaController extends Controller
{
    public function __construct(private ServicioAsistencia $asistencia) {}

    public function __invoke(Request $request): JsonResponse
    {
        $lote = $request->has('eventos');
        $prefijo = $lote ? 'eventos.*.' : '';

        $datos = $request->validate([
            ...($lote ? ['eventos' => ['required', 'array', 'min:1', 'max:100']] : []),
            $prefijo.'id_evento' => ['nullable', 'string', 'max:100'],
            $prefijo.'id_externo' => ['required', 'string', 'max:255'],
            $prefijo.'tipo' => ['required', 'in:entrada,salida'],
            $prefijo.'momento' => ['nullable', 'string', 'date'],
        ]);

        $eventos = $lote ? $datos['eventos'] : [$datos];
        $momentos = [];
        $ahora = now();
        foreach ($eventos as $i => $evento) {
            $momentos[$i] = $this->momento($evento['momento'] ?? null, $lote ? "eventos.$i.momento" : 'momento') ?? $ahora;
        }

        // Un lote se procesa en orden cronológico (sin momento: ahora) y se responde en el orden recibido.
        $orden = array_keys($eventos);
        usort($orden, fn ($a, $b) => $momentos[$a]->getTimestamp() <=> $momentos[$b]->getTimestamp() ?: $a <=> $b);

        $resultados = [];
        foreach ($orden as $i) {
            $evento = $eventos[$i];
            $resultados[$i] = [
                'id_evento' => $evento['id_evento'] ?? null,
                'id_externo' => $evento['id_externo'],
                'tipo' => $evento['tipo'],
                ...$this->asistencia->procesar($evento['id_externo'], $evento['tipo'], $momentos[$i], $evento['id_evento'] ?? null),
            ];
        }

        ksort($resultados);

        return response()->json($lote ? ['resultados' => array_values($resultados)] : $resultados[0]);
    }

    /** Sin zona es hora local de los usuarios; si no se puede interpretar es un 422, nunca un 500. */
    private function momento(?string $valor, string $campo): ?Carbon
    {
        if ($valor === null) {
            return null;
        }

        try {
            return HoraLocal::interpretar($valor);
        } catch (Throwable) {
            throw ValidationException::withMessages([$campo => 'El momento no es una fecha válida.']);
        }
    }
}
