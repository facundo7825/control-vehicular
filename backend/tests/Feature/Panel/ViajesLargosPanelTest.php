<?php

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use App\Filament\Pages\RotacionViajesLargos;
use App\Filament\Resources\Viajes\Pages\CreateViaje;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// 2026-10-01 12:00 UTC = 09:00 en Buenos Aires.
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

/** Formulario "Nuevo viaje" de un viaje largo: salida el 03/10 06:00 y regreso 20:00, hora local. */
function formularioLargo(Usuario $solicitante, array $cambios = []): array
{
    return [
        'solicitante_id' => $solicitante->id,
        'tipo' => 'largo',
        'programado_para' => '2026-10-03 06:00:00',
        'regreso_estimado' => '2026-10-03 20:00:00',
        'origen_direccion' => 'Tribunales, Catamarca',
        'origen_lat' => -28.469,
        'origen_lng' => -65.779,
        'destino_direccion' => 'Tinogasta',
        'destino_lat' => -28.063,
        'destino_lng' => -67.565,
        'pasajeros' => 'Dra. Pérez y un perito',
        'motivo' => 'Inspección ocular',
        ...$cambios,
    ];
}

/** Viaje largo de un chofer, sin pasar por el servicio (aceptado por defecto). */
function largoDelPanel(Usuario $chofer, Carbon $salida, int $minutos, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'tipo' => TipoViaje::Largo,
        'modo' => ModoViaje::Especifico,
        'chofer_id' => $chofer->id,
        'vehiculo_id' => Vehiculo::factory()->create()->id,
        'estado' => EstadoViaje::Aceptado,
        'aceptado_en' => now(),
        'programado_para' => $salida,
        'regreso_estimado' => $salida->copy()->addMinutes($minutos),
        'duracion_estimada_min' => $minutos,
        ...$attrs,
    ]);
}

/** Viaje largo ya finalizado de un chofer. */
function largoFinalizado(Usuario $chofer, string $salidaUtc, string $destino, array $attrs = []): Viaje
{
    $salida = Carbon::parse($salidaUtc);

    return largoDelPanel($chofer, $salida, 600, [
        'estado' => EstadoViaje::Finalizado,
        'destino_direccion' => $destino,
        'iniciado_en' => $salida,
        'finalizado_en' => $salida->copy()->addMinutes(600),
        ...$attrs,
    ]);
}

describe('nuevo viaje largo', function () {
    it('crea un viaje largo asignado al chofer y al vehículo elegidos', function () {
        $solicitante = Usuario::factory()->create();
        $chofer = Usuario::factory()->chofer()->create();
        $vehiculo = Vehiculo::factory()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo($solicitante, ['chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Viaje creado');

        $viaje = Viaje::sole();
        expect($viaje)
            ->tipo->toBe(TipoViaje::Largo)
            ->estado->toBe(EstadoViaje::Aceptado)
            ->solicitante_id->toBe($solicitante->id)
            ->chofer_id->toBe($chofer->id)
            ->vehiculo_id->toBe($vehiculo->id)
            ->destino_direccion->toBe('Tinogasta')
            ->pasajeros->toBe('Dra. Pérez y un perito')
            ->motivo->toBe('Inspección ocular')
            ->duracion_estimada_min->toBe(840)
            // Hora local (UTC-3).
            ->and($viaje->programado_para->equalTo(Carbon::parse('2026-10-03 09:00:00', 'UTC')))->toBeTrue()
            ->and($viaje->regreso_estimado->equalTo(Carbon::parse('2026-10-03 23:00:00', 'UTC')))->toBeTrue();
    });

    it('pide el regreso, el chofer y el vehículo y no crea nada sin ellos', function () {
        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo(Usuario::factory()->create(), ['regreso_estimado' => null]))
            ->call('create')
            ->assertHasFormErrors(['regreso_estimado' => 'required', 'chofer_id' => 'required', 'vehiculo_id' => 'required']);

        expect(Viaje::count())->toBe(0);
    });

    it('sin una franja válida no ofrece choferes ni vehículos', function () {
        Usuario::factory()->chofer()->create();
        Vehiculo::factory()->create();

        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo(Usuario::factory()->create(), ['regreso_estimado' => '2026-10-03 05:00:00']))
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => $campo->getOptions() === [])
            ->assertFormFieldExists('vehiculo_id', fn (Select $campo) => $campo->getOptions() === [])
            ->assertFormSet(['chofer_id' => null, 'vehiculo_id' => null]);
    });

    it('muestra una regla de negocio como notificación y no crea nada', function () {
        $solicitante = Usuario::factory()->create();
        $chofer = Usuario::factory()->chofer()->create();
        $vehiculo = Vehiculo::factory()->create();

        $componente = Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo($solicitante, ['chofer_id' => $chofer->id, 'vehiculo_id' => $vehiculo->id]));

        // Mientras tanto, otro encargado manda ese vehículo a otro viaje largo en la misma franja.
        largoDelPanel(Usuario::factory()->chofer()->create(), Carbon::parse('2026-10-03 10:00:00'), 120, ['vehiculo_id' => $vehiculo->id]);

        $componente->call('create')
            ->assertNotified(Notification::make()->danger()->title('No se pudo crear el viaje')
                ->body('El vehículo elegido está en otro viaje largo en esa franja.'))
            ->assertNoRedirect();

        expect(Viaje::where('solicitante_id', $solicitante->id)->count())->toBe(0);
    });

    it('preselecciona al chofer al que le toca y muestra su último viaje largo en cada opción', function () {
        $beto = Usuario::factory()->chofer()->create(['nombre' => 'Beto']);
        largoFinalizado($beto, '2026-09-12 12:00:00', 'Tinogasta');
        $carla = Usuario::factory()->chofer()->create(['nombre' => 'Carla']);
        largoFinalizado($carla, '2026-08-01 12:00:00', 'Belén');
        $ana = Usuario::factory()->chofer()->create(['nombre' => 'Ana']);
        // Dario tiene otro viaje largo en la franja: no aparece.
        $dario = Usuario::factory()->chofer()->create(['nombre' => 'Dario']);
        largoDelPanel($dario, Carbon::parse('2026-10-03 12:00:00'), 300);

        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo(Usuario::factory()->create()))
            ->assertFormSet(['chofer_id' => $ana->id])
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => $campo->getOptions() === [
                $ana->id => 'Ana — Último viaje largo: Nunca',
                $carla->id => 'Carla — Último viaje largo: 01/08 (Belén)',
                $beto->id => 'Beto — Último viaje largo: 12/09 (Tinogasta)',
            ]);
    });

    it('ofrece primero el vehículo habitual del chofer y después los libres en la franja', function () {
        $otro = Vehiculo::factory()->create(['patente' => 'BB222BB']);
        $habitual = Vehiculo::factory()->create(['patente' => 'AA111AA']);
        Vehiculo::factory()->create(['activo' => false]);
        $chofer = Usuario::factory()->chofer()->create(['vehiculo_habitual_id' => $habitual->id]);
        // Un vehículo en otro viaje largo que se superpone no se ofrece.
        $ocupado = largoDelPanel(Usuario::factory()->chofer()->create(), Carbon::parse('2026-10-03 12:00:00'), 60)->vehiculo;

        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo(Usuario::factory()->create()))
            ->assertFormSet(['chofer_id' => $chofer->id, 'vehiculo_id' => $habitual->id])
            ->assertFormFieldExists('vehiculo_id', fn (Select $campo) => array_keys($campo->getOptions()) === [$habitual->id, $otro->id]
                && ! array_key_exists($ocupado->id, $campo->getOptions())
                && str_contains($campo->getOptions()[$habitual->id], 'habitual'));
    });

    it('marca los vehículos que son el habitual de otro chofer', function () {
        $suyo = Vehiculo::factory()->create(['patente' => 'AA111AA', 'marca' => 'Ford', 'modelo' => 'Ka']);
        $dePedro = Vehiculo::factory()->create(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Etios']);
        $libre = Vehiculo::factory()->create(['patente' => 'CC333CC', 'marca' => 'Fiat', 'modelo' => 'Cronos']);
        $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Ana', 'vehiculo_habitual_id' => $suyo->id]);
        Usuario::factory()->chofer()->create(['nombre' => 'Pedro', 'vehiculo_habitual_id' => $dePedro->id]);
        // Un chofer inactivo no cuenta.
        Usuario::factory()->chofer()->create(['nombre' => 'Inactivo', 'activo' => false, 'vehiculo_habitual_id' => $libre->id]);

        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo(Usuario::factory()->create(), ['chofer_id' => $chofer->id]))
            ->assertFormFieldExists('vehiculo_id', fn (Select $campo) => $campo->getOptions() === [
                $suyo->id => 'AA111AA — Ford Ka (habitual)',
                $dePedro->id => 'AB123CD — Toyota Etios (habitual de Pedro)',
                $libre->id => 'CC333CC — Fiat Cronos',
            ]);
    });

    it('recalcula el chofer sugerido al cambiar la franja', function () {
        $ana = Usuario::factory()->chofer()->create(['nombre' => 'Ana']);
        $beto = Usuario::factory()->chofer()->create(['nombre' => 'Beto']);
        largoFinalizado($beto, '2026-09-12 12:00:00', 'Tinogasta');
        // Ana tiene un viaje largo el 05/10.
        largoDelPanel($ana, Carbon::parse('2026-10-05 12:00:00'), 300);

        Livewire::test(CreateViaje::class)
            ->fillForm(formularioLargo(Usuario::factory()->create()))
            ->assertFormSet(['chofer_id' => $ana->id])
            ->fillForm(['programado_para' => '2026-10-05 06:00:00', 'regreso_estimado' => '2026-10-05 20:00:00'])
            ->assertFormSet(['chofer_id' => $beto->id])
            ->assertFormFieldExists('chofer_id', fn (Select $campo) => array_keys($campo->getOptions()) === [$beto->id]);
    });

    it('el tipo "Viaje largo" está entre las opciones del formulario', function () {
        $this->get(ViajeResource::getUrl('create'))->assertOk()->assertSee('Viaje largo');
    });
});

describe('rotación de viajes largos', function () {
    it('lista a los choferes por a quién le toca, con el último, los recientes y el próximo enlazados', function () {
        $beto = Usuario::factory()->chofer()->create(['nombre' => 'Beto']);
        $ultimoBeto = largoFinalizado($beto, '2026-09-12 12:00:00', 'Tinogasta');
        largoFinalizado($beto, '2026-08-20 12:00:00', 'Andalgalá');
        $carla = Usuario::factory()->chofer()->create(['nombre' => 'Carla']);
        largoFinalizado($carla, '2026-08-01 12:00:00', 'Belén');
        $ana = Usuario::factory()->chofer()->create(['nombre' => 'Ana']);
        $proximo = largoDelPanel($ana, Carbon::parse('2026-10-10 12:00:00'), 300, ['destino_direccion' => 'Córdoba']);
        Usuario::factory()->chofer()->create(['nombre' => 'Inactivo', 'activo' => false]);

        $this->get(RotacionViajesLargos::getUrl())->assertOk();

        Livewire::test(RotacionViajesLargos::class)
            ->assertSeeInOrder(['Ana', 'Carla', 'Beto'])
            ->assertDontSee('Inactivo')
            ->assertSee('12/09/2026')
            ->assertSee('Tinogasta')
            ->assertSee('10/10/2026 09:00')
            ->assertSee('Córdoba')
            ->assertSee(ViajeResource::getUrl('view', ['record' => $ultimoBeto]))
            ->assertSee(ViajeResource::getUrl('view', ['record' => $proximo]))
            ->assertSee('Nunca');

        expect(Livewire::test(RotacionViajesLargos::class)->instance()->filas()->pluck('recientes', 'chofer.nombre')->all())
            ->toBe(['Ana' => 0, 'Carla' => 1, 'Beto' => 2]);
    });
});

describe('reasignar y cambiar el vehículo de un viaje largo', function () {
    it('"Reasignar" ofrece los choferes libres en el orden de la rotación, con su último viaje largo', function () {
        $actual = Usuario::factory()->chofer()->create(['nombre' => 'Actual']);
        $viaje = largoDelPanel($actual, Carbon::parse('2026-10-03 09:00:00'), 840);
        $beto = Usuario::factory()->chofer()->create(['nombre' => 'Beto']);
        largoFinalizado($beto, '2026-09-12 12:00:00', 'Tinogasta');
        $carla = Usuario::factory()->chofer()->create(['nombre' => 'Carla']);
        largoFinalizado($carla, '2026-08-01 12:00:00', 'Belén');
        $ana = Usuario::factory()->chofer()->create(['nombre' => 'Ana']);
        $ocupado = Usuario::factory()->chofer()->create(['nombre' => 'Dario']);
        largoDelPanel($ocupado, Carbon::parse('2026-10-03 12:00:00'), 60);

        expect(ViajeResource::choferesElegibles($viaje))->toBe([
            $ana->id => 'Ana — Último viaje largo: Nunca',
            $carla->id => 'Carla — Último viaje largo: 01/08 (Belén)',
            $beto->id => 'Beto — Último viaje largo: 12/09 (Tinogasta)',
        ]);
    });

    it('"Cambiar vehículo" cambia el vehículo de un viaje largo aceptado, con los vehículos libres en su franja', function () {
        $chofer = Usuario::factory()->chofer()->create();
        $viaje = largoDelPanel($chofer, Carbon::parse('2026-10-03 09:00:00'), 840);
        $anterior = $viaje->vehiculo_id;
        $nuevo = Vehiculo::factory()->create(['patente' => 'ZZ999ZZ']);
        $ocupado = largoDelPanel(Usuario::factory()->chofer()->create(), Carbon::parse('2026-10-03 12:00:00'), 60)->vehiculo;

        Livewire::test(ViewViaje::class, ['record' => $viaje->id])
            ->assertActionVisible('cambiarVehiculo')
            ->mountAction('cambiarVehiculo')
            ->assertFormFieldExists('vehiculo_id', fn (Select $campo) => isset($campo->getOptions()[$nuevo->id])
                && ! isset($campo->getOptions()[$anterior])
                && ! isset($campo->getOptions()[$ocupado->id]))
            ->setActionData(['vehiculo_id' => $nuevo->id])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Vehículo cambiado');

        expect($viaje->refresh()->vehiculo_id)->toBe($nuevo->id)
            ->and($viaje->chofer_id)->toBe($chofer->id)
            ->and($viaje->estado)->toBe(EstadoViaje::Aceptado);
    });

    it('"Cambiar vehículo" no aparece una vez que el chofer salió ni en otros viajes', function () {
        $enCamino = largoDelPanel(Usuario::factory()->chofer()->create(), now()->subMinutes(10), 600, ['estado' => EstadoViaje::EnCamino]);
        $reserva = Viaje::factory()->create([
            'tipo' => TipoViaje::Reserva, 'estado' => EstadoViaje::Aceptado,
            'chofer_id' => Usuario::factory()->chofer()->create()->id, 'programado_para' => now()->addDay(),
        ]);

        Livewire::test(ViewViaje::class, ['record' => $enCamino->id])->assertActionHidden('cambiarVehiculo');
        Livewire::test(ViewViaje::class, ['record' => $reserva->id])->assertActionHidden('cambiarVehiculo');
    });
});

describe('detalle de un viaje largo', function () {
    it('muestra regreso, pasajeros, duración real y el aviso de fuera del horario laboral', function () {
        // De 05:00 a 20:30 hora local: empezó antes de las 7 y terminó después de las 18.
        $viaje = largoFinalizado(Usuario::factory()->chofer()->create(), '2026-09-30 08:00:00', 'Tinogasta', [
            'finalizado_en' => Carbon::parse('2026-09-30 23:30:00'),
            'pasajeros' => 'Dra. Pérez',
        ]);

        Livewire::test(ViewViaje::class, ['record' => $viaje->id])
            ->assertSee('Regreso estimado')
            ->assertSee('Dra. Pérez')
            ->assertSee('15 h 30 min')
            ->assertSee('Fuera del horario laboral');
    });

    it('no muestra el aviso si el viaje largo fue dentro del horario laboral', function () {
        // De 08:00 a 17:00 hora local.
        $viaje = largoFinalizado(Usuario::factory()->chofer()->create(), '2026-09-30 11:00:00', 'Belén', [
            'finalizado_en' => Carbon::parse('2026-09-30 20:00:00'),
        ]);

        Livewire::test(ViewViaje::class, ['record' => $viaje->id])
            ->assertSee('9 h 00 min')
            ->assertDontSee('Fuera del horario laboral');
    });
});
