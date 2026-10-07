<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\RotacionViajesLargos as Rotacion;
use App\Support\HoraLocal;
use BackedEnum;
use DateTimeInterface;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Rotación de viajes largos" (solo lectura): los choferes activos en el orden en que les toca el próximo viaje
 * largo, con su último viaje largo finalizado, cuántos hicieron en los últimos 90 días y el próximo programado.
 */
class RotacionViajesLargos extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Rotación de viajes largos';

    protected static ?string $title = 'Rotación de viajes largos';

    protected static ?string $slug = 'rotacion-viajes-largos';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.rotacion-viajes-largos';

    /** Se calcula una vez por pedido. */
    private ?Collection $filas = null;

    /**
     * @return Collection<int, array{chofer: Usuario, ultimo: ?array{viaje_id: int, fecha: Carbon, destino: ?string}, recientes: int, proximo: ?Viaje}>
     */
    public function filas(): Collection
    {
        return $this->filas ??= app(Rotacion::class)->todos();
    }

    public function diasRecientes(): int
    {
        return Rotacion::DIAS_RECIENTES;
    }

    public function urlViaje(int|Viaje $viaje): string
    {
        return ViajeResource::getUrl('view', ['record' => $viaje]);
    }

    /** En la zona de los usuarios. */
    public function fecha(DateTimeInterface $momento, string $formato = 'd/m/Y'): string
    {
        return HoraLocal::formatear($momento, $formato);
    }
}
