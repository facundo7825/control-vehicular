<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Viaje;
use App\Servicios\ExportadorExcel;
use App\Support\HoraLocal;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListViajes extends ListRecords
{
    protected static string $resource = ViajeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo viaje'),
            Action::make('exportar')
                ->label('Exportar a Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportar()),
        ];
    }

    /** Los viajes que muestra la tabla (filtros, búsqueda y orden activos), sin paginar, en hora local. */
    public function exportar(): StreamedResponse
    {
        $viajes = $this->getTableQueryForExport()
            ->with(['solicitante', 'chofer', 'vehiculo'])
            ->lazy(500)
            ->map(fn (Viaje $v): array => [
                $v->id,
                $v->tipo->getLabel(),
                $v->estado->getLabel(),
                $v->obligatorio ? 'Sí' : 'No',
                $v->solicitante?->nombre,
                $v->solicitante?->cargo,
                $v->chofer?->nombre,
                $v->vehiculo?->patente,
                ViajeResource::describirLugar($v, 'origen'),
                ViajeResource::describirLugar($v, 'destino'),
                $v->motivo,
                $v->created_at,
                $v->programado_para,
                $v->aceptado_en,
                $v->llego_en,
                $v->iniciado_en,
                $v->finalizado_en,
                $v->cancelado_en,
                $v->cancelado_por,
                $v->motivo_cancelacion,
            ]);

        return app(ExportadorExcel::class)->descargar(
            'viajes-'.HoraLocal::formatear(now(), 'Y-m-d-Hi').'.xlsx',
            [
                'Número', 'Tipo', 'Estado', 'Obligatorio', 'Solicitante', 'Cargo', 'Chofer', 'Vehículo',
                'Origen', 'Destino', 'Motivo', 'Pedido', 'Programado para', 'Aceptado', 'Llegó', 'Inició',
                'Finalizó', 'Cancelado', 'Canceló', 'Motivo de cancelación',
            ],
            $viajes,
            'Viajes',
        );
    }
}
