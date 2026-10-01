<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Viaje;
use App\Servicios\ExportadorExcel;
use App\Support\HoraLocal;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
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

    /** Días que se exportan si no hay filtro de fecha, para no volcar el historial entero en un archivo. */
    private const DIAS_SIN_FILTRO = 366;

    /**
     * Los viajes que muestra la tabla (filtros, búsqueda y orden activos), sin paginar, en hora local.
     * Sin filtro de fecha, solo los de los últimos 366 días (y las reservas futuras), con un aviso.
     */
    public function exportar(): StreamedResponse
    {
        $consulta = $this->getTableQueryForExport();
        $fecha = $this->tableFilters['fecha'] ?? [];
        if (blank($fecha['desde'] ?? null) && blank($fecha['hasta'] ?? null)) {
            $desde = now(config('vehiculos.zona_horaria'))->subDays(self::DIAS_SIN_FILTRO - 1);
            ViajeResource::filtrarPorFecha($consulta, $desde->format('Y-m-d'), null);
            Notification::make()
                ->info()
                ->title('Se exportaron los viajes de los últimos '.self::DIAS_SIN_FILTRO.' días (desde el '.$desde->format('d/m/Y').'). Para otro período, filtrá por fecha.')
                ->send();
        }

        $viajes = $consulta
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
