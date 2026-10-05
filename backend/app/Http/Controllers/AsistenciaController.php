<?php

namespace App\Http\Controllers;

use App\Models\EventoAsistencia;
use App\Servicios\ServicioAsistencia;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** POST /api/asistencia/eventos: un fichaje o un lote {"eventos": [...]} de hasta 100. */
class AsistenciaController extends Controller
{
    /** Ventana aceptada para el momento de un fichaje, respecto de ahora. */
    public const MINUTOS_FUTURO = 5;

    public const DIAS_PASADO = 7;

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
            $momentos[$i] = $this->momento($evento['momento'] ?? null, $lote ? "eventos.$i.momento" : 'momento', $ahora) ?? $ahora;
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
                ...$this->procesar($evento, $momentos[$i], $lote),
            ];
        }

        ksort($resultados);

        return response()->json($lote ? ['resultados' => array_values($resultados)] : $resultados[0]);
    }

    /**
     * En un lote, una falla inesperada de un evento no corta los demás: ese evento queda ignorado (sin
     * registrar, así un reenvío lo vuelve a intentar) y en el log solo va la clase del error.
     *
     * @return array{resultado: string, motivo: string}
     */
    private function procesar(array $evento, Carbon $momento, bool $lote): array
    {
        try {
            return $this->asistencia->procesar($evento['id_externo'], $evento['tipo'], $momento, $evento['id_evento'] ?? null);
        } catch (Throwable $error) {
            if (! $lote) {
                throw $error;
            }
            Log::error('Asistencia: error al procesar un evento del lote', ['error' => $error::class]);

            return ['resultado' => EventoAsistencia::IGNORADO, 'motivo' => 'error interno al procesar el evento'];
        }
    }

    /**
     * Sin zona es hora local de los usuarios. Fuera de la ventana (más de 5 minutos en el futuro o más de
     * 7 días atrás) o imposible de interpretar es un 422: nunca un 500 ni una fecha que trabe el orden.
     */
    private function momento(?string $valor, string $campo, Carbon $ahora): ?Carbon
    {
        if ($valor === null) {
            return null;
        }

        try {
            $momento = HoraLocal::interpretar($valor);
        } catch (Throwable) {
            throw ValidationException::withMessages([$campo => 'El momento no es una fecha válida.']);
        }

        if ($momento->gt($ahora->copy()->addMinutes(self::MINUTOS_FUTURO))) {
            throw ValidationException::withMessages([$campo => 'El momento no puede estar más de '.self::MINUTOS_FUTURO.' minutos en el futuro.']);
        }
        if ($momento->lt($ahora->copy()->subDays(self::DIAS_PASADO))) {
            throw ValidationException::withMessages([$campo => 'El momento no puede tener más de '.self::DIAS_PASADO.' días de antigüedad.']);
        }

        return $momento;
    }
}
