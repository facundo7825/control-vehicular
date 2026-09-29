<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Viajes\ViajeResource;
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

        return [
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
}
