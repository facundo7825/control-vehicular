<?php

namespace App\Filament\Widgets;

use App\Servicios\EstadisticasPanel;
use Filament\Widgets\ChartWidget;

class PedidosPorHora extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Pedidos por hora del día';

    protected ?string $description = 'Últimos 30 días, en hora local';

    protected ?string $pollingInterval = '60s';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return [
            'labels' => array_map(fn (int $h) => sprintf('%02d h', $h), range(0, 23)),
            'datasets' => [
                ['label' => 'Pedidos', 'data' => app(EstadisticasPanel::class)->pedidosPorHora()],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
