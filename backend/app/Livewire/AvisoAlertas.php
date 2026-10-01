<?php

namespace App\Livewire;

use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Alerta;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Aviso en vivo de alertas nuevas en todo el panel (render hook BODY_END). Cada 10 s busca alertas
 * pendientes con id mayor al último visto, muestra una notificación por cada una (hasta 3, más un
 * resumen) y pide al navegador que suene, salvo que el admin lo haya silenciado.
 */
class AvisoAlertas extends Component
{
    private const MAXIMO_AVISOS = 3;

    #[Locked]
    public int $ultimoId = 0;

    /** Última alerta avisada, en la sesión: el panel no es SPA y cada página monta el aviso de nuevo. */
    private const CLAVE_SESION = 'alertas.ultimo_visto';

    public function mount(): void
    {
        // Sigue desde la última avisada en la sesión, así no se pierde lo creado entre la última consulta y
        // la navegación. En una sesión nueva lo que ya existía se da por visto.
        if (! session()->has(self::CLAVE_SESION)) {
            session([self::CLAVE_SESION => (int) Alerta::max('id')]);
        }
        $this->ultimoId = (int) session(self::CLAVE_SESION);
    }

    public function revisar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario?->esAdmin() && $usuario->activo, 403);

        $nuevas = Alerta::pendientes()->where('id', '>', $this->ultimoId);
        $total = (clone $nuevas)->count();
        if ($total === 0) {
            return;
        }

        // Límite conocido: los ids se asignan al insertar y no al confirmar; una alerta con un id menor que
        // confirma después de una consulta que ya vio uno mayor no se avisa (queda en la lista de alertas).
        $this->ultimoId = (int) (clone $nuevas)->max('id');
        session([self::CLAVE_SESION => max($this->ultimoId, (int) session(self::CLAVE_SESION, 0))]);

        foreach ($nuevas->orderBy('id')->limit(self::MAXIMO_AVISOS)->get() as $alerta) {
            $this->notificar(
                AlertaResource::TIPOS[$alerta->tipo] ?? 'Alerta nueva',
                $alerta->mensaje,
                $alerta->viaje_id
                    ? ViajeResource::getUrl('view', ['record' => $alerta->viaje_id])
                    : AlertaResource::getUrl('index'),
            );
        }

        if ($total > self::MAXIMO_AVISOS) {
            $resto = $total - self::MAXIMO_AVISOS;
            $this->notificar($resto === 1 ? 'Y 1 alerta más' : "Y $resto alertas más", null, AlertaResource::getUrl('index'));
        }

        $this->dispatch('alertas-nuevas');
    }

    private function notificar(string $titulo, ?string $cuerpo, string $url): void
    {
        Notification::make()
            ->warning()
            ->persistent()
            ->title($titulo)
            ->body($cuerpo)
            ->actions([Action::make('ver')->label('Ver')->url($url)])
            ->send();
    }

    public function render(): View
    {
        return view('livewire.aviso-alertas');
    }
}
