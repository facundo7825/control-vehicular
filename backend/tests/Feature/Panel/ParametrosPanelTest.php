<?php

use App\Filament\Pages\ConfiguracionParametros;
use App\Models\OfertaViaje;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Despachador;
use App\Servicios\Parametros;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(Usuario::factory()->admin()->create()));

it('lista todos los parámetros con su valor por defecto y el actual', function () {
    Parametro::create(['clave' => 'oferta_segundos', 'valor' => '45']);

    $filas = Livewire::test(ConfiguracionParametros::class)
        ->assertOk()
        ->assertSee('Segundos para responder un pedido inmediato')
        ->instance()
        ->filas();

    expect(array_keys($filas))->toBe(array_keys(config('vehiculos.parametros')))
        ->and($filas['oferta_segundos'])->toMatchArray(['por_defecto' => 30, 'actual' => 45, 'personalizado' => true])
        ->and($filas['colchon_reservas_min'])->toMatchArray(['por_defecto' => 30, 'actual' => 30, 'personalizado' => false]);
});

it('guarda un valor nuevo que rige en la operación siguiente', function () {
    Queue::fake();

    Livewire::test(ConfiguracionParametros::class)
        ->callAction(TestAction::make('editar')->table('oferta_segundos'), data: ['valor' => 45])
        ->assertHasNoActionErrors()
        ->assertNotified('Parámetro guardado');

    expect(Parametro::find('oferta_segundos')->valor)->toBe('45')
        ->and(app(Parametros::class)->entero('oferta_segundos'))->toBe(45);

    choferEnTurno();
    app(Despachador::class)->despachar(Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]));
    $oferta = OfertaViaje::sole();
    expect((int) $oferta->ofrecido_en->diffInSeconds($oferta->vence_en))->toBe(45);
});

it('rechaza valores que no son enteros positivos', function (mixed $valor) {
    Livewire::test(ConfiguracionParametros::class)
        ->callAction(TestAction::make('editar')->table('colchon_reservas_min'), data: ['valor' => $valor])
        ->assertHasActionErrors(['valor']);

    expect(Parametro::count())->toBe(0);
})->with([0, -5, 'diez', '']);

it('restablece el valor por defecto', function () {
    Parametro::create(['clave' => 'colchon_reservas_min', 'valor' => '10']);

    Livewire::test(ConfiguracionParametros::class)
        ->assertActionHidden(TestAction::make('restablecer')->table('oferta_segundos'))
        ->callAction(TestAction::make('restablecer')->table('colchon_reservas_min'));

    expect(Parametro::count())->toBe(0)
        ->and(app(Parametros::class)->entero('colchon_reservas_min'))->toBe(30);
});

it('el horario laboral acepta horas de 0 a 23, con el inicio antes del fin', function (string $clave, mixed $valor, bool $valido) {
    $prueba = Livewire::test(ConfiguracionParametros::class)
        ->callAction(TestAction::make('editar')->table($clave), data: ['valor' => $valor]);

    if ($valido) {
        $prueba->assertHasNoActionErrors();
        expect(app(Parametros::class)->entero($clave))->toBe((int) $valor);
    } else {
        $prueba->assertHasActionErrors(['valor']);
        expect(Parametro::count())->toBe(0);
    }
})->with([
    'inicio a las 0' => ['horario_laboral_inicio', 0, true],
    'inicio a las 6' => ['horario_laboral_inicio', 6, true],
    'fin a las 23' => ['horario_laboral_fin', 23, true],
    'fin a las 24' => ['horario_laboral_fin', 24, false],
    'inicio negativo' => ['horario_laboral_inicio', -1, false],
    'inicio igual al fin (18)' => ['horario_laboral_inicio', 18, false],
    'fin antes del inicio (7)' => ['horario_laboral_fin', 6, false],
    'fin igual al inicio (7)' => ['horario_laboral_fin', 7, false],
]);
