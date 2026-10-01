<?php

namespace App\Filament\Widgets;

use App\Servicios\EstadisticasPanel;
use Filament\Widgets\ChartWidget;

class ViajesPorDia extends ChartWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Viajes por día';

    protected ?string $description = 'Últimos 14 días, en hora local';

    protected ?string $pollingInterval = '60s';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $serie = app(EstadisticasPanel::class)->viajesPorDia();

        return [
            'labels' => $serie['etiquetas'],
            'datasets' => [
                ['label' => 'Finalizados', 'data' => $serie['finalizados'], 'backgroundColor' => '#16a34a', 'borderColor' => '#16a34a'],
                ['label' => 'Cancelados', 'data' => $serie['cancelados'], 'backgroundColor' => '#9ca3af', 'borderColor' => '#9ca3af'],
                ['label' => 'Sin chofer', 'data' => $serie['sin_chofer'], 'backgroundColor' => '#f59e0b', 'borderColor' => '#f59e0b'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
