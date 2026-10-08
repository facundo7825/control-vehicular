<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\Pages\CreateViaje;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Mapas\BuscadorLugares;
use App\Mapas\ServicioMapas;
use App\Mapas\ServicioMapasFalso;
use App\Models\Alerta;
use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

// 2026-10-01 12:00 UTC = 09:00 en Buenos Aires.
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs($this->admin = Usuario::factory()->admin()->create());
});

/** Datos del formulario "Nuevo viaje" con coordenadas cargadas a mano. */
function datosNuevoViaje(Usuario $solicitante, array $cambios = []): array
{
    return [
        'solicitante_id' => $solicitante->id,
        'tipo' => 'inmediato',
        'origen_direccion' => 'Tribunales',
        'origen_lat' => -34.6037,
        'origen_lng' => -58.3816,
        'destino_direccion' => 'Casa de Gobierno',
        'destino_lat' => -34.6080,
        'destino_lng' => -58.3700,
        'modo' => 'mas_cercano',
        'motivo' => 'Audiencia',
        ...$cambios,
    ];
}

/** Lee el xlsx que descargó el componente: filas con los valores de cada celda (la primera, los encabezados). */
function filasExcelViajes(Testable $componente): array
{
    $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($archivo, base64_decode(data_get($componente->effects, 'download.content')));

    $filas = [];
    $reader = new Reader;
    $reader->open($archivo);
    foreach ($reader->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $filas[] = $fila->toArray();
        }
    }
    $reader->close();
    unlink($archivo);

    return $filas;
}

describe('nuevo viaje', function () {
    it('crea un inmediato para otra persona, obligatorio según su cargo, y lo despacha', function () {
        CargoPrioritario::create(['cargo' => 'Juez', 'obligatorio' => true]);
        $juez = Usuario::factory()->create(['cargo' => 'Juez']);
        $chofer = choferEnTurno(-34.6037, -58.3816);

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje($juez))
            ->call('create')
            ->assertHasNoFormErrors();

        $viaje = Viaje::sole();
        expect($viaje)
            ->solicitante_id->toBe($juez->id)
            ->tipo->toBe(TipoViaje::Inmediato)
            ->obligatorio->toBeTrue()
            ->origen_direccion->toBe('Tribunales')
            ->destino_lat->toBe(-34.608)
            ->motivo->toBe('Audiencia')
            // Obligatorio: el despachador se lo asigna directo al chofer libre más cercano.
            ->estado->toBe(EstadoViaje::Aceptado)
            ->chofer_id->toBe($chofer->id);
    });

    it('crea un inmediato no obligatorio para un chofer específico: se le ofrece a él', function () {
        $solicitante = Usuario::factory()->create();
        $elegido = choferEnTurno(-34.70, -58.50);
        choferEnTurno(-34.6037, -58.3816); // más cercano, pero no elegido

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje($solicitante, ['modo' => 'especifico', 'chofer_id' => $elegido->id]))
            ->call('create')
            ->assertHasNoFormErrors();

        $viaje = Viaje::sole();
        expect($viaje->obligatorio)->toBeFalse()
            ->and($viaje->estado)->toBe(EstadoViaje::Ofrecido)
            ->and(OfertaViaje::sole()->chofer_id)->toBe($elegido->id);
    });

    it('crea una reserva para otra persona, con la hora en hora local, y se la ofrece al chofer elegido', function () {
        $solicitante = Usuario::factory()->create();
        $chofer = Usuario::factory()->chofer()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje($solicitante, [
                'tipo' => 'reserva',
                'programado_para' => '2026-10-02 10:00:00',
                'modo' => 'especifico',
                'chofer_id' => $chofer->id,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $viaje = Viaje::sole();
        expect($viaje)
            ->solicitante_id->toBe($solicitante->id)
            ->tipo->toBe(TipoViaje::Reserva)
            ->obligatorio->toBeFalse()
            ->and($viaje->programado_para->equalTo(Carbon::parse('2026-10-02 13:00:00', 'UTC')))->toBeTrue()
            ->and(OfertaViaje::sole()->chofer_id)->toBe($chofer->id);
    });

    it('crea una reserva obligatoria con cualquier chofer disponible: queda asignada', function () {
        CargoPrioritario::create(['cargo' => 'Juez', 'obligatorio' => true]);
        $juez = Usuario::factory()->create(['cargo' => 'Juez']);
        $chofer = Usuario::factory()->chofer()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje($juez, [
                'tipo' => 'reserva',
                'programado_para' => '2026-10-02 10:00:00',
                'modo' => 'cualquiera_disponible',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Viaje::sole())
            ->obligatorio->toBeTrue()
            ->estado->toBe(EstadoViaje::Aceptado)
            ->chofer_id->toBe($chofer->id);
    });

    it('solo ofrece choferes elegibles y modos según el tipo', function () {
        $libre = choferEnTurno();
        $ocupado = choferEnTurno();
        Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
        $sinTurno = Usuario::factory()->chofer()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje(Usuario::factory()->create(), ['modo' => 'especifico']))
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => array_keys($campo->getOptions()) === [$libre->id])
            ->assertFormFieldExists('modo', fn (Select $campo) => array_keys($campo->getOptions()) === ['mas_cercano', 'especifico'])
            ->fillForm(['tipo' => 'reserva', 'programado_para' => '2026-10-02 10:00:00', 'modo' => 'especifico'])
            ->assertFormFieldExists('modo', fn (Select $campo) => array_keys($campo->getOptions()) === ['cualquiera_disponible', 'especifico'])
            // Para una reserva cuenta la agenda, no el turno.
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => array_keys($campo->getOptions()) === [$libre->id, $ocupado->id, $sinTurno->id]);
    });

    it('no vuelve a pedir la duración de la ruta en cada render si la franja no cambió', function () {
        $mapas = new class extends ServicioMapasFalso
        {
            public int $llamadas = 0;

            public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
            {
                $this->llamadas++;

                return parent::duracionRuta($oLat, $oLng, $dLat, $dLng);
            }
        };
        $this->app->instance(ServicioMapas::class, $mapas);
        $chofer = Usuario::factory()->chofer()->create();

        $componente = Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje(Usuario::factory()->create(), [
                'tipo' => 'reserva', 'programado_para' => '2026-10-02 10:00:00', 'modo' => 'especifico',
            ]))
            ->fillForm(['motivo' => 'Otro motivo'])
            ->fillForm(['motivo' => 'Uno más'])
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => array_keys($campo->getOptions()) === [$chofer->id]);

        expect($mapas->llamadas)->toBe(1);

        $componente->fillForm(['programado_para' => '2026-10-02 11:00:00'])
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => array_keys($campo->getOptions()) === [$chofer->id]);

        expect($mapas->llamadas)->toBe(2);
    });

    it('muestra un error de negocio como notificación y no crea nada', function () {
        $solicitante = Usuario::factory()->create();
        Viaje::factory()->create(['solicitante_id' => $solicitante->id, 'estado' => EstadoViaje::Buscando]);

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje($solicitante))
            ->call('create')
            // El texto de la app ("Ya tenés...") le habla al solicitante; en el panel se habla de él.
            ->assertNotified(Notification::make()->danger()->title('No se pudo crear el viaje')
                ->body('El solicitante ya tiene un viaje en curso.'))
            ->assertNoRedirect();

        expect(Viaje::count())->toBe(1);
    });

    it('no crea una reserva sin la anticipación mínima', function () {
        Usuario::factory()->chofer()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(datosNuevoViaje(Usuario::factory()->create(), [
                'tipo' => 'reserva',
                'programado_para' => '2026-10-01 09:30:00',
                'modo' => 'cualquiera_disponible',
            ]))
            ->call('create')
            ->assertNotified('No se pudo crear el viaje');

        expect(Viaje::count())->toBe(0);
    });

    it('valida los campos obligatorios y que el solicitante sea un solicitante activo', function () {
        $chofer = Usuario::factory()->chofer()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(['solicitante_id' => $chofer->id, 'origen_lat' => null, 'modo' => 'especifico', 'chofer_id' => null])
            ->call('create')
            ->assertHasFormErrors(['solicitante_id', 'origen_lat', 'destino_lat', 'chofer_id' => 'required']);

        expect(Viaje::count())->toBe(0);
    });

    it('busca lugares con el botón Buscar y al elegir uno fija la dirección y las coordenadas', function () {
        Livewire::test(CreateViaje::class)
            ->fillForm(['origen_busqueda' => 'Tribunales'])
            ->callAction(TestAction::make('buscar')->schemaComponent('origen_busqueda'))
            ->assertFormFieldExists('origen_lugar', fn (Select $campo) => array_values($campo->getOptions()) === [
                'Tribunales 1, Buenos Aires, Argentina',
                'Tribunales 2, Buenos Aires, Argentina',
                'Tribunales 3, Buenos Aires, Argentina',
            ])
            ->fillForm(['origen_lugar' => 1])
            ->assertFormSet([
                'origen_direccion' => 'Tribunales 2, Buenos Aires, Argentina',
                'origen_lat' => -34.6017,
                'origen_lng' => -58.3796,
            ])
            // El destino se busca cerca del origen elegido.
            ->fillForm(['destino_busqueda' => 'Congreso'])
            ->callAction(TestAction::make('buscar')->schemaComponent('destino_busqueda'))
            ->fillForm(['destino_lugar' => 0])
            ->assertFormSet([
                'destino_direccion' => 'Congreso 1, Buenos Aires, Argentina',
                'destino_lat' => -34.6007,
                'destino_lng' => -58.3786,
            ]);
    });

    it('avisa si la búsqueda es muy corta o no encuentra nada', function () {
        Livewire::test(CreateViaje::class)
            ->fillForm(['origen_busqueda' => 'ab'])
            ->callAction(TestAction::make('buscar')->schemaComponent('origen_busqueda'))
            ->assertNotified('Escribí al menos 3 letras para buscar.')
            ->assertFormFieldExists('origen_lugar', fn (Select $campo) => $campo->getOptions() === []);

        $this->app->instance(BuscadorLugares::class, new class implements BuscadorLugares
        {
            public function buscar(string $texto, ?float $lat, ?float $lng): array
            {
                return [];
            }
        });

        Livewire::test(CreateViaje::class)
            ->fillForm(['origen_busqueda' => 'Ningún lugar'])
            ->callAction(TestAction::make('buscar')->schemaComponent('origen_busqueda'))
            ->assertNotified('No se encontraron lugares.')
            ->assertFormFieldExists('origen_lugar', fn (Select $campo) => $campo->getOptions() === []);
    });

    it('la lista tiene el botón para crear un viaje', function () {
        $this->get(ViajeResource::getUrl('create'))->assertOk()->assertSee('Coordenadas');
        Livewire::test(ListViajes::class)->assertActionVisible('create');
    });
});

describe('asignar chofer', function () {
    it('asigna desde la lista un viaje sin chofer: resuelve la alerta', function () {
        $viaje = Viaje::factory()->create();
        app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::SinChofer);
        $chofer = choferEnTurno();

        Livewire::test(ListViajes::class)
            ->callAction(TestAction::make('asignar')->table($viaje), data: ['chofer_id' => $chofer->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Chofer asignado');

        expect($viaje->fresh())->estado->toBe(EstadoViaje::Aceptado)->chofer_id->toBe($chofer->id)
            ->and(Alerta::where('tipo', Alerta::VIAJE_SIN_CHOFER)->sole()->resuelta_en)->not->toBeNull();
    });

    it('en el detalle un error de negocio al asignar llega como notificación y no asigna', function () {
        $viaje = Viaje::factory()->create();
        app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::SinChofer);
        $chofer = choferEnTurno();
        // Por ejemplo, otro admin lo asignó entre que se abrió el modal y se confirmó.
        $this->mock(ServicioViaje::class)->shouldReceive('asignarPorAdmin')->once()
            ->andThrow(new ReglaNegocio('El viaje ya tiene chofer o terminó; no se puede asignar.'));

        Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
            ->callAction('asignar', data: ['chofer_id' => $chofer->id])
            ->assertHasNoActionErrors()
            ->assertNotified('El viaje ya tiene chofer o terminó; no se puede asignar.')
            ->assertNotNotified('Chofer asignado');

        expect($viaje->fresh())->estado->toBe(EstadoViaje::SinChofer)->chofer_id->toBeNull();
    });

    it('asigna desde el detalle un viaje ofrecido: vence la oferta', function () {
        $ofrecido = choferEnTurno(-34.6037, -58.3816);
        $viaje = Viaje::factory()->create();
        app(Despachador::class)->despachar($viaje);
        $elegido = choferEnTurno(-34.70, -58.50);

        Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
            ->assertActionHidden('reasignar')
            ->callAction('asignar', data: ['chofer_id' => $elegido->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Chofer asignado');

        expect($viaje->fresh())->estado->toBe(EstadoViaje::Aceptado)->chofer_id->toBe($elegido->id)
            ->and(OfertaViaje::where('chofer_id', $ofrecido->id)->sole()->resultado)->toBe(ResultadoOferta::Expirada);
    });

    it('asigna un viaje que se está buscando y respeta la elegibilidad', function () {
        $libre = choferEnTurno();
        $ocupado = choferEnTurno();
        Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);

        Livewire::test(ListViajes::class)
            ->callAction(TestAction::make('asignar')->table($viaje), data: ['chofer_id' => $ocupado->id])
            ->assertHasActionErrors(['chofer_id']);
        expect($viaje->fresh()->estado)->toBe(EstadoViaje::Buscando);

        Livewire::test(ListViajes::class)
            ->callAction(TestAction::make('asignar')->table($viaje), data: ['chofer_id' => $libre->id])
            ->assertHasNoActionErrors();
        expect($viaje->fresh()->chofer_id)->toBe($libre->id);
    });

    it('para una reserva lista a los choferes con la franja libre', function () {
        $libre = Usuario::factory()->chofer()->create();
        $ocupado = Usuario::factory()->chofer()->create();
        reservaAceptada($ocupado, Carbon::parse('2026-10-02 15:30'));
        $reserva = reservaBuscando(['estado' => EstadoViaje::SinChofer]);

        Livewire::test(ViewViaje::class, ['record' => $reserva->getRouteKey()])
            ->callAction('asignar', data: ['chofer_id' => $ocupado->id])
            ->assertHasActionErrors(['chofer_id']);

        Livewire::test(ViewViaje::class, ['record' => $reserva->getRouteKey()])
            ->callAction('asignar', data: ['chofer_id' => $libre->id])
            ->assertHasNoActionErrors();
        expect($reserva->fresh()->chofer_id)->toBe($libre->id);
    });

    it('muestra las reglas de negocio del servicio como notificación', function () {
        $chofer = choferEnTurno();
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);
        $this->mock(ServicioViaje::class)
            ->shouldReceive('asignarPorAdmin')
            ->andThrow(new ReglaNegocio('El chofer elegido no está libre.'));

        Livewire::test(ListViajes::class)
            ->callAction(TestAction::make('asignar')->table($viaje), data: ['chofer_id' => $chofer->id])
            ->assertNotified('El chofer elegido no está libre.')
            ->assertNotNotified('Chofer asignado');
    });

    it('no ofrece asignar un viaje que ya tiene chofer o terminó', function () {
        $aceptado = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::Aceptado]);
        $finalizado = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado]);
        $buscando = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);

        Livewire::test(ListViajes::class)
            ->assertActionHidden(TestAction::make('asignar')->table($aceptado))
            ->assertActionHidden(TestAction::make('asignar')->table($finalizado))
            ->assertActionVisible(TestAction::make('asignar')->table($buscando));

        Livewire::test(ViewViaje::class, ['record' => $aceptado->getRouteKey()])
            ->assertActionHidden('asignar')
            ->assertActionVisible('reasignar');
    });
});

describe('lista', function () {
    it('filtra por solicitante y busca por número, solicitante, chofer y direcciones', function () {
        $ana = Usuario::factory()->create(['nombre' => 'Ana Pérez']);
        $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Carlos Gómez']);
        $deAna = Viaje::factory()->create(['solicitante_id' => $ana->id, 'origen_direccion' => 'Talcahuano 550']);
        $otro = Viaje::factory()->create(['destino_direccion' => 'Avenida de Mayo 525']);
        $conChofer = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

        Livewire::test(ListViajes::class)
            ->filterTable('solicitante', $ana->id)
            ->assertCanSeeTableRecords([$deAna])
            ->assertCanNotSeeTableRecords([$otro, $conChofer])
            ->resetTableFilters()
            ->searchTable('Talcahuano')
            ->assertCanSeeTableRecords([$deAna])
            ->assertCanNotSeeTableRecords([$otro, $conChofer])
            ->searchTable('Avenida de Mayo')
            ->assertCanSeeTableRecords([$otro])
            ->assertCanNotSeeTableRecords([$deAna, $conChofer])
            ->searchTable('Ana Pé')
            ->assertCanSeeTableRecords([$deAna])
            ->assertCanNotSeeTableRecords([$otro, $conChofer])
            ->searchTable('Gómez')
            ->assertCanSeeTableRecords([$conChofer])
            ->assertCanNotSeeTableRecords([$deAna, $otro])
            ->searchTable((string) $conChofer->id)
            ->assertCanSeeTableRecords([$conChofer])
            ->assertCanNotSeeTableRecords([$deAna, $otro]);
    });

    it('exporta a Excel respetando los filtros y la búsqueda, con columnas en español y horas locales', function () {
        $ana = Usuario::factory()->create(['nombre' => 'Ana Pérez', 'cargo' => 'Jueza']);
        $chofer = choferEnTurno();
        $viaje = Viaje::factory()->create([
            'solicitante_id' => $ana->id,
            'chofer_id' => $chofer->id,
            'vehiculo_id' => $chofer->turnoAbierto->vehiculo_id,
            'estado' => EstadoViaje::Finalizado,
            'obligatorio' => true,
            'origen_direccion' => 'Talcahuano 550',
            'destino_direccion' => null,
            'motivo' => '=HIPERVINCULO("x")',
            'created_at' => now()->subHour(),
            'aceptado_en' => now()->subMinutes(50),
            'finalizado_en' => now()->subMinutes(10),
        ]);
        $otroDeAna = Viaje::factory()->create(['solicitante_id' => $ana->id, 'origen_direccion' => 'Lavalle 1']);
        Viaje::factory()->create(['origen_direccion' => 'Talcahuano 550']);

        $componente = Livewire::test(ListViajes::class)
            ->filterTable('solicitante', $ana->id)
            ->searchTable('Talcahuano')
            ->callAction('exportar')
            ->assertFileDownloaded('viajes-2026-10-01-0900.xlsx');
        $filas = filasExcelViajes($componente);

        expect($filas[0])->toBe([
            'Número', 'Tipo', 'Estado', 'Obligatorio', 'Solicitante', 'Cargo', 'Chofer', 'Vehículo',
            'Origen', 'Destino', 'Motivo', 'Pedido', 'Programado para', 'Aceptado', 'Llegó', 'Inició',
            'Finalizó', 'Cancelado', 'Canceló', 'Motivo de cancelación',
        ])->and($filas)->toHaveCount(2);

        expect($filas[1])->toBe([
            $viaje->id, 'Inmediato', 'Finalizado', 'Sí', 'Ana Pérez', 'Jueza', $chofer->nombre,
            $chofer->turnoAbierto->vehiculo->patente, 'Talcahuano 550', 'Ubicación marcada en el mapa', "'=HIPERVINCULO(\"x\")",
            '01/10/2026 08:00', '', '01/10/2026 08:10', '', '', '01/10/2026 08:50', '', '', '',
        ]);

        // Sin búsqueda, salen los dos viajes de Ana.
        $sinBusqueda = Livewire::test(ListViajes::class)
            ->filterTable('solicitante', $ana->id)
            ->callAction('exportar');
        expect(array_column(array_slice(filasExcelViajes($sinBusqueda), 1), 0))
            ->toEqualCanonicalizing([$viaje->id, $otroDeAna->id]);
    });

    it('sin filtro de fecha exporta solo los últimos 366 días y lo avisa', function () {
        // Hoy es 01/10/2026: los últimos 366 días van del 01/10/2025 al 01/10/2026 (más las reservas futuras).
        $reciente = Viaje::factory()->create(['created_at' => now()->subDays(10)]);
        $limite = Viaje::factory()->create(['created_at' => Carbon::parse('2025-10-01 03:00:00')]); // 00:00 local
        $futura = Viaje::factory()->create(['tipo' => TipoViaje::Reserva, 'created_at' => now()->subYears(2), 'programado_para' => now()->addDay()]);
        $viejo = Viaje::factory()->create(['created_at' => Carbon::parse('2025-10-01 02:59:00')]); // 30/09 local

        $sinFecha = Livewire::test(ListViajes::class)
            ->callAction('exportar')
            ->assertNotified('Se exportaron los viajes de los últimos 366 días (desde el 01/10/2025). Para otro período, filtrá por fecha.');
        expect(array_column(array_slice(filasExcelViajes($sinFecha), 1), 0))
            ->toEqualCanonicalizing([$reciente->id, $limite->id, $futura->id]);

        $conFecha = Livewire::test(ListViajes::class)
            ->filterTable('fecha', ['desde' => '2025-01-01'])
            ->callAction('exportar')
            ->assertNotNotified();
        expect(array_column(array_slice(filasExcelViajes($conFecha), 1), 0))
            ->toEqualCanonicalizing([$reciente->id, $limite->id, $futura->id, $viejo->id]);
    });
});
