<?php

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Filament\Pages\Reportes;
use App\Mapas\Distancia;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Servicios\ExportadorExcel;
use App\Servicios\KilometrosRecorridos;
use App\Servicios\ReportesPanel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;

// 2026-10-15 12:00 UTC = 09:00 en Buenos Aires. El día local 10/10 empieza a las 03:00 UTC.
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

function viajeConRecorrido(Usuario $chofer, Vehiculo $vehiculo, array $puntos, array $attrs = []): Viaje
{
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id,
        'estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDay(),
        ...$attrs,
    ]);
    foreach ($puntos as $i => [$lat, $lng]) {
        PuntoRecorrido::create([
            'viaje_id' => $viaje->id, 'lat' => $lat, 'lng' => $lng,
            'registrado_en' => now()->subDay()->addMinutes($i),
        ]);
    }
    if ($viaje->estado === EstadoViaje::Finalizado && ! array_key_exists('metros_recorridos', $attrs)) {
        // Como al finalizar de verdad (MaquinaEstadosViaje): los metros quedan guardados en el viaje.
        $viaje->update(['metros_recorridos' => KilometrosRecorridos::metrosDe($viaje->id)]);
    }

    return $viaje;
}

it('suma los km por haversine del recorrido de los viajes finalizados en el rango', function () {
    $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Ana Chofer']);
    $vehiculo = Vehiculo::factory()->create(['patente' => 'AB123CD']);
    $puntos = [[-34.6000, -58.3800], [-34.6100, -58.3800], [-34.6100, -58.3900]];
    viajeConRecorrido($chofer, $vehiculo, $puntos);
    // Finalizado fuera del rango y cancelado en el rango: no suman km.
    viajeConRecorrido($chofer, $vehiculo, $puntos, ['finalizado_en' => Carbon::parse('2026-09-20 12:00:00')]);
    viajeConRecorrido($chofer, $vehiculo, $puntos, [
        'estado' => EstadoViaje::Cancelado, 'finalizado_en' => null, 'cancelado_en' => now()->subHour(),
    ]);

    $esperado = round((Distancia::metros(-34.6000, -58.3800, -34.6100, -58.3800)
        + Distancia::metros(-34.6100, -58.3800, -34.6100, -58.3900)) / 1000, 2);

    $reportes = app(ReportesPanel::class);
    $choferes = $reportes->porChofer('2026-10-01', '2026-10-31');
    $vehiculos = $reportes->porVehiculo('2026-10-01', '2026-10-31');

    expect($esperado)->toBeGreaterThan(1.0)
        ->and($choferes)->toHaveCount(1)
        ->and($choferes[0])->toMatchArray(['chofer' => 'Ana Chofer', 'finalizados' => 1, 'cancelados' => 1, 'km' => $esperado])
        ->and($vehiculos)->toHaveCount(1)
        ->and($vehiculos[0])->toMatchArray(['patente' => 'AB123CD', 'finalizados' => 1, 'km' => $esperado]);
});

it('recorta las horas de turno al rango y cuenta los turnos abiertos hasta ahora', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $vehiculo = Vehiculo::factory()->create();
    // Empieza antes del rango (09/10 23:00 UTC) y termina adentro (10/10 05:00 UTC): cuentan 2 h.
    Turno::factory()->create([
        'chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id,
        'inicio' => Carbon::parse('2026-10-09 23:00:00'), 'fin' => Carbon::parse('2026-10-10 05:00:00'),
    ]);
    // Sigue abierto: se corta en el fin del rango (13/10 03:00 UTC), 7 h.
    Turno::factory()->create([
        'chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id,
        'inicio' => Carbon::parse('2026-10-12 20:00:00'), 'fin' => null,
    ]);
    // Fuera del rango.
    Turno::factory()->create([
        'chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id,
        'inicio' => Carbon::parse('2026-10-08 10:00:00'), 'fin' => Carbon::parse('2026-10-08 12:00:00'),
    ]);

    $reportes = app(ReportesPanel::class);
    expect($reportes->porChofer('2026-10-10', '2026-10-12')[0]['horas_turno'])->toBe(9.0)
        ->and($reportes->porVehiculo('2026-10-10', '2026-10-12')[0]['horas_turno'])->toBe(9.0)
        // Con el rango hasta fin de mes, el turno abierto cuenta hasta ahora (15/10 12:00 UTC): 2 + 64 h.
        ->and($reportes->porChofer('2026-10-10', '2026-10-31')[0]['horas_turno'])->toBe(66.0);
});

it('promedia la llegada de aceptado a llegó en los viajes inmediatos', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado, 'finalizado_en' => now(),
        'aceptado_en' => now()->subMinutes(30), 'llego_en' => now()->subMinutes(24),
    ]);
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCurso,
        'aceptado_en' => now()->subMinutes(15), 'llego_en' => now()->subMinutes(5),
    ]);
    // Una reserva se acepta con días de anticipación: no cuenta.
    reservaAceptada($chofer, now()->subHour(), attrs: [
        'aceptado_en' => now()->subDays(3), 'llego_en' => now()->subHour(), 'estado' => EstadoViaje::Llego,
    ]);

    expect(app(ReportesPanel::class)->porChofer('2026-10-01', '2026-10-31')[0]['llegada_promedio_min'])->toBe(8.0);
});

it('suma las horas reales de los viajes largos finalizados en el rango', function () {
    $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Ana Chofer']);
    $largo = fn (string $inicio, int $minutos, array $attrs = []) => Viaje::factory()->create([
        'tipo' => TipoViaje::Largo, 'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado,
        'programado_para' => Carbon::parse($inicio)->subMinutes(30),
        'iniciado_en' => Carbon::parse($inicio), 'finalizado_en' => Carbon::parse($inicio)->addMinutes($minutos),
        'metros_recorridos' => 0, ...$attrs,
    ]);
    $largo('2026-10-10 10:00:00', 600);
    $largo('2026-10-12 10:00:00', 270);
    // Fuera del rango y un inmediato: no suman.
    $largo('2026-09-10 10:00:00', 600);
    $largo('2026-10-11 10:00:00', 120, ['tipo' => TipoViaje::Inmediato, 'programado_para' => null]);

    expect(app(ReportesPanel::class)->porChofer('2026-10-01', '2026-10-31')[0])
        ->horas_largos->toBe(14.5)
        ->finalizados->toBe(3);

    Livewire::test(Reportes::class)
        ->assertSee('Horas en viajes largos')
        ->assertSee('14,5');
});

it('usa por defecto el mes actual en días locales', function () {
    // 01/11 01:00 UTC es todavía el 31/10 en Buenos Aires.
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00'));

    expect(app(ReportesPanel::class)->rangoPorDefecto())->toBe(['desde' => '2026-10-01', 'hasta' => '2026-10-31']);

    Livewire::test(Reportes::class)
        ->assertOk()
        ->assertSet('filtros.desde', '2026-10-01')
        ->assertSet('filtros.hasta', '2026-10-31')
        ->assertSee('Por chofer')
        ->assertSee('Por vehículo');

    $this->get(Reportes::getUrl())->assertOk()->assertSee('Exportar a Excel');
});

it('los km salen de los metros guardados en cada viaje, sin leer el recorrido', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $vehiculo = Vehiculo::factory()->create();
    viajeConRecorrido($chofer, $vehiculo, [], ['metros_recorridos' => 12345]);

    DB::enableQueryLog();
    $reportes = app(ReportesPanel::class);
    $choferes = $reportes->porChofer('2026-10-01', '2026-10-31');
    $vehiculos = $reportes->porVehiculo('2026-10-01', '2026-10-31');
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($choferes[0]['km'])->toBe(12.35)
        ->and($vehiculos[0]['km'])->toBe(12.35)
        ->and($consultas->filter(fn (string $sql) => str_contains($sql, 'recorrido_viaje')))->toBeEmpty();
});

it('avisa cuando el rango tiene viajes finalizados sin km guardados', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $vehiculo = Vehiculo::factory()->create();
    viajeConRecorrido($chofer, $vehiculo, [], ['metros_recorridos' => 1000]);
    // Finalizados antes de que se guardaran los metros y con el recorrido ya borrado: sin dato.
    foreach (['2026-06-10 12:00:00', '2026-06-11 12:00:00'] as $momento) {
        viajeConRecorrido($chofer, $vehiculo, [], ['finalizado_en' => Carbon::parse($momento)])
            ->update(['metros_recorridos' => null]);
    }

    $reportes = app(ReportesPanel::class);

    expect($reportes->avisoKmSinDatos('2026-10-01', '2026-10-31'))->toBeNull()
        ->and($reportes->avisoKmSinDatos('2026-06-01', '2026-06-30'))
        ->toBe('2 viajes finalizados del rango no tienen km: su recorrido se borró antes de que se guardara la distancia.');

    Livewire::test(Reportes::class)
        ->assertDontSee('no tienen km')
        ->set('filtros.desde', '2026-06-01')
        ->set('filtros.hasta', '2026-06-30')
        ->assertSee('2 viajes finalizados del rango no tienen km');
});

it('muestra los datos del rango elegido en la página', function () {
    $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Beto Chofer']);
    $vehiculo = Vehiculo::factory()->create(['patente' => 'ZZ999ZZ']);
    viajeConRecorrido($chofer, $vehiculo, [[-34.60, -58.38], [-34.61, -58.38]], ['finalizado_en' => Carbon::parse('2026-09-15 12:00:00')]);

    Livewire::test(Reportes::class)
        ->assertDontSee('Beto Chofer')
        ->set('filtros.desde', '2026-09-01')
        ->assertSee('Beto Chofer')
        ->assertSee('ZZ999ZZ');
});

it('exporta un xlsx con una hoja por chofer y otra por vehículo', function () {
    $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Ana Chofer']);
    $vehiculo = Vehiculo::factory()->create(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Corolla']);
    viajeConRecorrido($chofer, $vehiculo, [[-34.60, -58.38], [-34.61, -58.38]], [
        'aceptado_en' => now()->subDay()->subMinutes(10), 'llego_en' => now()->subDay()->subMinutes(4),
    ]);
    Turno::factory()->create([
        'chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id,
        'inicio' => now()->subHours(3), 'fin' => now()->subHour(),
    ]);

    $componente = Livewire::test(Reportes::class)
        ->callAction('exportar')
        ->assertFileDownloaded('reportes-2026-10-01-a-2026-10-31.xlsx');

    $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($archivo, base64_decode(data_get($componente->effects, 'download.content')));

    $hojas = [];
    $reader = new Reader;
    $reader->open($archivo);
    foreach ($reader->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $hojas[$hoja->getName()][] = $fila->toArray();
        }
    }
    $reader->close();
    unlink($archivo);

    $km = round(Distancia::metros(-34.60, -58.38, -34.61, -58.38) / 1000, 2);

    expect(array_keys($hojas))->toBe(['Choferes', 'Vehículos'])
        ->and($hojas['Choferes'])->toEqual([
            ['Chofer', 'Viajes finalizados', 'Viajes cancelados', 'Km recorridos', 'Horas de turno', 'Horas en viajes largos', 'Llegada promedio (min)'],
            ['Ana Chofer', 1, 0, $km, 2, 0, 6],
        ])
        ->and($hojas['Vehículos'])->toEqual([
            ['Patente', 'Vehículo', 'Viajes finalizados', 'Km recorridos', 'Horas en turno'],
            ['AB123CD', 'Toyota Corolla', 1, $km, 2],
        ]);
});

/** Lee un xlsx descargado por Livewire: [hoja => [filas de celdas OpenSpout]]. */
function leerXlsxDescargado(Testable $componente): array
{
    $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($archivo, base64_decode(data_get($componente->effects, 'download.content')));

    $hojas = [];
    $reader = new Reader;
    $reader->open($archivo);
    foreach ($reader->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $hojas[$hoja->getName()][] = $fila->getCells();
        }
    }
    $reader->close();
    unlink($archivo);

    return $hojas;
}

it('exporta los textos como texto y neutraliza los que parecen fórmulas', function () {
    $vehiculo = Vehiculo::factory()->create(['patente' => 'AB123CD']);
    foreach (['=1+1', '@SUMA(A1)', '+54 11 1234', '-x', 'Ana'] as $nombre) {
        viajeConRecorrido(Usuario::factory()->chofer()->create(['nombre' => $nombre]), $vehiculo, []);
    }

    $hojas = leerXlsxDescargado(Livewire::test(Reportes::class)->callAction('exportar'));
    $nombres = array_map(fn (array $celdas) => $celdas[0], array_slice($hojas['Choferes'], 1));

    expect($nombres)->each->toBeInstanceOf(StringCell::class)
        ->and(array_map(fn (StringCell $c) => $c->getValue(), $nombres))
        ->toEqualCanonicalizing(["'=1+1", "'@SUMA(A1)", '+54 11 1234', '-x', 'Ana'])
        // Los números siguen siendo numéricos.
        ->and($hojas['Choferes'][1][1])->toBeInstanceOf(NumericCell::class);
});

it('cierra el archivo y borra los temporales si falla a mitad de la escritura', function () {
    $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reportes-test-'.uniqid();
    mkdir($carpeta);
    $filas = (function () {
        yield ['uno'];
        throw new RuntimeException('falló la consulta');
    })();

    $respuesta = (new ExportadorExcel($carpeta))->descargar('x.xlsx', ['Columna'], $filas);

    ob_start();
    try {
        expect(fn () => $respuesta->sendContent())->toThrow(RuntimeException::class, 'falló la consulta');
    } finally {
        ob_end_clean();
    }

    expect(array_diff(scandir($carpeta), ['.', '..']))->toBe([]);
    rmdir($carpeta);
});

it('da vuelta un rango invertido y usa el mes actual si una fecha falta o no es válida', function () {
    $pagina = Livewire::test(Reportes::class)
        ->set('filtros.desde', '2026-10-20')
        ->set('filtros.hasta', '2026-10-05');
    expect($pagina->instance()->rango())->toBe(['desde' => '2026-10-05', 'hasta' => '2026-10-20']);

    $pagina->set('filtros.desde', 'no es fecha')->set('filtros.hasta', null);
    expect($pagina->instance()->rango())->toBe(['desde' => '2026-10-01', 'hasta' => '2026-10-31']);
});

it('acota el rango a 366 días y lo avisa', function () {
    $pagina = Livewire::test(Reportes::class)
        ->assertDontSee('puede abarcar hasta')
        ->set('filtros.desde', '2024-01-01')
        ->set('filtros.hasta', '2026-10-31')
        ->assertSee('El rango puede abarcar hasta 366 días: se muestran del 01/01/2024 al 31/12/2024.');

    expect($pagina->instance()->rango())->toBe(['desde' => '2024-01-01', 'hasta' => '2024-12-31']);

    $pagina->callAction('exportar')->assertFileDownloaded('reportes-2024-01-01-a-2024-12-31.xlsx');
});
