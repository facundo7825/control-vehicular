<?php

namespace App\Http\Controllers;

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Excepciones\AccionNoPermitida;
use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use App\Models\PuntoRecorrido;
use App\Models\Viaje;
use App\Servicios\Parametros;
use App\Servicios\ServicioViaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViajeController extends Controller
{
    private const RELACIONES = ['chofer', 'vehiculo', 'solicitante'];

    private const LIMITE_HISTORIAL = 50;

    private const MAX_PUNTOS_RECORRIDO = 500;

    public function __construct(private ServicioViaje $viajes) {}

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'modo' => ['required', 'in:mas_cercano,especifico'],
            'chofer_id' => ['required_if:modo,especifico', 'nullable', 'integer'],
            'origen_lat' => ['required', 'numeric', 'between:-90,90'],
            'origen_lng' => ['required', 'numeric', 'between:-180,180'],
            'origen_direccion' => ['nullable', 'string', 'max:255'],
            'destino_lat' => ['required', 'numeric', 'between:-90,90'],
            'destino_lng' => ['required', 'numeric', 'between:-180,180'],
            'destino_direccion' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        return (new ViajeResource($this->viajes->pedir($request->user(), $datos)))
            ->response()
            ->setStatusCode(201);
    }

    /** "Mis viajes" del solicitante: próximas reservas e historial (spec 7, solicitante 6). */
    public function index(Request $request): JsonResponse
    {
        $id = $request->user()->id;

        $proximas = Viaje::where('solicitante_id', $id)
            ->where('tipo', TipoViaje::Reserva)
            ->whereIn('estado', EstadoViaje::enProgreso())
            ->with(self::RELACIONES)
            ->orderBy('programado_para')
            ->get();

        $historial = Viaje::where('solicitante_id', $id)
            ->whereIn('estado', [EstadoViaje::Finalizado, EstadoViaje::Cancelado, EstadoViaje::SinChofer])
            ->with(self::RELACIONES)
            ->latest('id')
            ->limit(self::LIMITE_HISTORIAL)
            ->get();

        return response()->json([
            'proximas' => ViajeResource::collection($proximas),
            'historial' => ViajeResource::collection($historial),
        ]);
    }

    public function actual(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $oferta = null;

        if ($usuario->esChofer()) {
            $viaje = Viaje::activosDeChofer($usuario->id)->first();
            // Las solicitudes de reserva no son urgentes: van en la agenda, no en la pantalla de oferta.
            $oferta = OfertaViaje::where('chofer_id', $usuario->id)
                ->where('resultado', ResultadoOferta::Pendiente)
                ->where('vence_en', '>', now())
                ->whereHas('viaje', fn ($q) => $q->where('tipo', TipoViaje::Inmediato))
                ->first();
        } else {
            // Inmediatos en progreso, o reservas que ya comenzaron (el chofer salió).
            $viaje = Viaje::where('solicitante_id', $usuario->id)
                ->where(fn ($q) => $q
                    ->where(fn ($i) => $i->where('tipo', TipoViaje::Inmediato)->whereIn('estado', EstadoViaje::enProgreso()))
                    ->orWhere(fn ($r) => $r->where('tipo', TipoViaje::Reserva)
                        ->whereIn('estado', [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso])))
                ->latest('id')
                ->first();
        }

        return response()->json([
            'viaje' => $viaje ? new ViajeResource($viaje->load(self::RELACIONES)) : null,
            'oferta' => $oferta ? [
                'id' => $oferta->id,
                'vence_en' => $oferta->vence_en->toIso8601String(),
                'viaje' => new ViajeResource($oferta->viaje->load(self::RELACIONES)),
            ] : null,
        ]);
    }

    /** Detalle de un viaje: lo ve su solicitante, su chofer actual o un admin. */
    public function show(Request $request, Viaje $viaje): ViajeResource
    {
        $this->autorizarVerViaje($request, $viaje);

        return new ViajeResource($viaje->load(self::RELACIONES));
    }

    /** Recorrido real del viaje (hasta 500 puntos) para dibujarlo en el detalle. */
    public function recorrido(Request $request, Viaje $viaje, Parametros $parametros): JsonResponse
    {
        $this->autorizarVerViaje($request, $viaje);

        $terminado = $viaje->finalizado_en ?? $viaje->cancelado_en;
        if ($terminado && $terminado->lt(now()->subDays($parametros->entero('retencion_recorrido_dias')))) {
            return response()->json(['puntos' => [], 'disponible' => false]);
        }

        $puntos = PuntoRecorrido::where('viaje_id', $viaje->id)
            ->orderBy('registrado_en')
            ->orderBy('id')
            ->select('lat', 'lng')
            ->toBase()
            ->get()
            ->map(fn ($p) => [(float) $p->lat, (float) $p->lng]);

        $total = $puntos->count();
        if ($total > self::MAX_PUNTOS_RECORRIDO) {
            $max = self::MAX_PUNTOS_RECORRIDO;
            // Índices repartidos de forma pareja: incluye siempre el primero y el último.
            $puntos = collect(range(0, $max - 1))
                ->map(fn ($i) => $puntos[intdiv($i * ($total - 1), $max - 1)]);
        }

        return response()->json(['puntos' => $puntos->values(), 'disponible' => $total > 0]);
    }

    private function autorizarVerViaje(Request $request, Viaje $viaje): void
    {
        $usuario = $request->user();

        if (! $usuario->esAdmin()
            && $viaje->solicitante_id !== $usuario->id
            && $viaje->chofer_id !== $usuario->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
    }

    public function avanzar(Request $request, Viaje $viaje): ViajeResource
    {
        $datos = $request->validate(['estado' => ['required', 'in:en_camino,llego,en_curso,finalizado']]);

        return new ViajeResource(
            $this->viajes->avanzar($viaje, $request->user(), EstadoViaje::from($datos['estado'])),
        );
    }

    public function cancelar(Request $request, Viaje $viaje): ViajeResource
    {
        $usuario = $request->user();

        if ($usuario->esChofer()) {
            $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

            return new ViajeResource($this->viajes->cancelarPorChofer($viaje, $usuario, $datos['motivo']));
        }

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        return new ViajeResource($this->viajes->cancelarPorSolicitante($viaje, $usuario, $datos['motivo'] ?? null));
    }
}
