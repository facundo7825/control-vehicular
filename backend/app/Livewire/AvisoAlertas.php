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

    public function mount(): void
    {
        // Lo que ya existía al abrir la página se da por visto.
        $this->ultimoId = (int) Alerta::max('id');
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

        $this->ultimoId = (int) (clone $nuevas)->max('id');

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
