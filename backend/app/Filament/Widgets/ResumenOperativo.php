<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Servicios\EstadisticasPanel;
use App\Servicios\ResumenPanel;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ResumenOperativo extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $resumen = app(ResumenPanel::class);
        $alertas = $resumen->alertasPendientes();
        $sinChofer = $resumen->viajesSinChoferRecientes();
        $sinSenal = $resumen->choferesSinSenalEnViaje();

        $estadisticas = app(EstadisticasPanel::class);
        $hoy = $estadisticas->viajesHoy();
        $espera = $estadisticas->esperaPromedioHoy();
        $choferes = $estadisticas->choferesEnTurno();

        return [
            Stat::make('Viajes hoy', $hoy['total'])
                ->description(self::cantidad($hoy['finalizados'], 'finalizado').' · '.self::cantidad($hoy['cancelados'], 'cancelado'))
                ->url(ViajeResource::getUrl('index')),
            Stat::make('Espera promedio hoy', $espera === null ? '—' : $this->minutos($espera))
                ->description('Del pedido a la llegada del chofer'),
            Stat::make('Choferes en turno', $choferes['total'])
                ->description(self::cantidad($choferes['libres'], 'libre')." · {$choferes['en_viaje']} en viaje"),
            Stat::make('Alertas sin resolver', $alertas)
                ->color($alertas > 0 ? 'danger' : 'success')
                ->url(AlertaResource::getUrl('index')),
            Stat::make('Viajes sin chofer (24 h)', $sinChofer)
                ->color($sinChofer > 0 ? 'warning' : 'success')
                ->url(ViajeResource::getUrl('index')),
            Stat::make('Choferes sin señal en viaje', $sinSenal->count())
                ->color($sinSenal->isNotEmpty() ? 'danger' : 'success')
                ->description($sinSenal->pluck('nombre')->join(', ') ?: 'Ninguno'),
        ];
    }

    /** "1 finalizado", "2 finalizados". */
    private static function cantidad(int $n, string $singular): string
    {
        return $n === 1 ? "1 $singular" : "$n {$singular}s";
    }

    private function minutos(float $minutos): string
    {
        return str_replace('.', ',', (string) round($minutos, 1)).' min';
    }
}
