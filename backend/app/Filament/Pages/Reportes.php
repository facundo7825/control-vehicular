<?php

namespace App\Filament\Pages;

use App\Servicios\ExportadorExcel;
use App\Servicios\ReportesPanel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Reportes por chofer y por vehículo de un rango de días locales (por defecto el mes actual), con
 * exportación a Excel. Los cálculos están en ReportesPanel; acá solo el rango y la presentación.
 *
 * @property-read Schema $form
 */
class Reportes extends Page
{
    /** Máximo de días (inclusive) de un reporte: acota el trabajo de un pedido. */
    public const MAXIMO_DIAS = 366;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?string $title = 'Reportes';

    protected static ?string $slug = 'reportes';

    protected static ?int $navigationSort = 18;

    protected string $view = 'filament.pages.reportes';

    /** @var array{desde?: string|null, hasta?: string|null} */
    public ?array $filtros = [];

    /** Se calcula una vez por pedido. */
    private ?array $datos = null;

    public function mount(): void
    {
        $this->form->fill(app(ReportesPanel::class)->rangoPorDefecto());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filtros')
            ->columns(2)
            ->components([
                DatePicker::make('desde')->label('Desde')->live(),
                DatePicker::make('hasta')->label('Hasta')->live(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')
                ->label('Exportar a Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->exportar()),
        ];
    }

    public function updatedFiltros(): void
    {
        $this->datos = null;
    }

    /** @return array{desde: string, hasta: string} el rango que se calcula (ver rangoElegido) */
    public function rango(): array
    {
        ['desde' => $desde, 'hasta' => $hasta] = $this->rangoElegido();

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    /** Aviso cuando el rango elegido supera el máximo y se acortó. */
    public function avisoRango(): ?string
    {
        $rango = $this->rangoElegido();

        return $rango['acotado']
            ? 'El rango puede abarcar hasta '.self::MAXIMO_DIAS.' días: se muestran del '
                .Carbon::parse($rango['desde'])->format('d/m/Y').' al '.Carbon::parse($rango['hasta'])->format('d/m/Y').'.'
            : null;
    }

    /**
     * El rango elegido. Si falta una fecha o no es válida se usa la del mes actual; si quedan invertidas
     * se dan vuelta; si abarca más de MAXIMO_DIAS días se acorta el final (acotado = true).
     *
     * @return array{desde: string, hasta: string, acotado: bool}
     */
    private function rangoElegido(): array
    {
        $defecto = app(ReportesPanel::class)->rangoPorDefecto();
        $desde = $this->fecha($this->filtros['desde'] ?? null) ?? $defecto['desde'];
        $hasta = $this->fecha($this->filtros['hasta'] ?? null) ?? $defecto['hasta'];
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $maximo = Carbon::parse($desde)->addDays(self::MAXIMO_DIAS - 1)->format('Y-m-d');

        return $hasta > $maximo
            ? ['desde' => $desde, 'hasta' => $maximo, 'acotado' => true]
            : ['desde' => $desde, 'hasta' => $hasta, 'acotado' => false];
    }

    /** @return array{choferes: array, vehiculos: array, aviso: string|null} */
    public function datos(): array
    {
        if ($this->datos !== null) {
            return $this->datos;
        }

        $reportes = app(ReportesPanel::class);
        ['desde' => $desde, 'hasta' => $hasta] = $this->rango();

        return $this->datos = [
            'choferes' => $reportes->porChofer($desde, $hasta),
            'vehiculos' => $reportes->porVehiculo($desde, $hasta),
            'aviso' => $reportes->avisoKmSinDatos($desde, $hasta),
        ];
    }

    public function exportar(): StreamedResponse
    {
        ['desde' => $desde, 'hasta' => $hasta] = $this->rango();
        $datos = $this->datos();

        return app(ExportadorExcel::class)->descargarHojas("reportes-$desde-a-$hasta.xlsx", [
            [
                'nombre' => 'Choferes',
                'encabezados' => ['Chofer', 'Viajes finalizados', 'Viajes cancelados', 'Km recorridos', 'Horas de turno', 'Llegada promedio (min)'],
                'filas' => array_map(fn (array $f) => [
                    $f['chofer'], $f['finalizados'], $f['cancelados'], $f['km'], $f['horas_turno'], $f['llegada_promedio_min'],
                ], $datos['choferes']),
            ],
            [
                'nombre' => 'Vehículos',
                'encabezados' => ['Patente', 'Vehículo', 'Viajes finalizados', 'Km recorridos', 'Horas en turno'],
                'filas' => array_map(fn (array $f) => [
                    $f['patente'], $f['vehiculo'], $f['finalizados'], $f['km'], $f['horas_turno'],
                ], $datos['vehiculos']),
            ],
        ]);
    }

    /** Formato de número para la tabla: coma decimal, como se escribe en el país. */
    public function numero(?float $valor, int $decimales = 1): string
    {
        return $valor === null ? '—' : number_format($valor, $decimales, ',', '.');
    }

    private function fecha(mixed $valor): ?string
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
