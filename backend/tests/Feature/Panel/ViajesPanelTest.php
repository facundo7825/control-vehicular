<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\RelationManagers\OfertasRelationManager;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\OfertaViaje;
use App\Models\PuntoRecorrido;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs($this->admin = Usuario::factory()->admin()->create());
});

it('filtra por tipo, estado, obligatorio y chofer', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $inmediato = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);
    $reserva = reservaBuscando(['obligatorio' => true]);
    $sinChofer = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    Livewire::test(ListViajes::class)
        ->assertCanSeeTableRecords([$inmediato, $reserva, $sinChofer])
        ->filterTable('tipo', TipoViaje::Reserva)
        ->assertCanSeeTableRecords([$reserva])
        ->assertCanNotSeeTableRecords([$inmediato, $sinChofer])
        ->resetTableFilters()
        ->filterTable('estado', [EstadoViaje::SinChofer, EstadoViaje::Aceptado])
        ->assertCanSeeTableRecords([$inmediato, $sinChofer])
        ->assertCanNotSeeTableRecords([$reserva])
        ->resetTableFilters()
        ->filterTable('obligatorio', true)
        ->assertCanSeeTableRecords([$reserva])
        ->assertCanNotSeeTableRecords([$inmediato, $sinChofer])
        ->resetTableFilters()
        ->filterTable('chofer', $chofer->id)
        ->assertCanSeeTableRecords([$inmediato])
        ->assertCanNotSeeTableRecords([$reserva, $sinChofer]);
});

it('filtra por fecha: programada en reservas y del pedido en inmediatos, en hora local', function () {
    // 2026-10-01 12:00 UTC = 09:00 en Buenos Aires.
    $hoy = Viaje::factory()->create();
    $ayer = Viaje::factory()->create(['created_at' => now()->subDay()]);
    $reservaManana = reservaBuscando(); // 2026-10-02 15:00 UTC
    // 2026-10-02 02:00 UTC es todavía 1/10 a las 23:00 en Buenos Aires.
    $reservaNocheLocal = reservaBuscando(['programado_para' => Carbon::parse('2026-10-02 02:00:00')]);

    Livewire::test(ListViajes::class)
        ->filterTable('fecha', ['desde' => '2026-10-01', 'hasta' => '2026-10-01'])
        ->assertCanSeeTableRecords([$hoy, $reservaNocheLocal])
        ->assertCanNotSeeTableRecords([$ayer, $reservaManana]);
});

it('muestra el detalle con línea de tiempo, ofertas y recorrido', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado,
        'aceptado_en' => now()->subMinutes(30), 'iniciado_en' => now()->subMinutes(20), 'finalizado_en' => now(),
    ]);
    $oferta = OfertaViaje::create([
        'viaje_id' => $viaje->id, 'chofer_id' => $chofer->id, 'resultado' => ResultadoOferta::Aceptada,
        'ofrecido_en' => now()->subMinutes(31), 'vence_en' => now()->subMinutes(30), 'respondido_en' => now()->subMinutes(30),
    ]);
    foreach ([10, 5] as $minutos) {
        PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => -34.6, 'lng' => -58.4, 'registrado_en' => now()->subMinutes($minutos)]);
    }

    $this->get(ViajeResource::getUrl('view', ['record' => $viaje]))
        ->assertOk()
        ->assertSee('Línea de tiempo')
        ->assertSee('01/10/2026 08:40') // iniciado_en en hora local
        ->assertSee('Puntos GPS');

    Livewire::test(OfertasRelationManager::class, ['ownerRecord' => $viaje, 'pageClass' => ViewViaje::class])
        ->assertCanSeeTableRecords([$oferta]);
});

it('cancela desde el panel con motivo obligatorio', function () {
    $viaje = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso, 'obligatorio' => true]);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('cancelar', data: ['motivo' => ''])
        ->assertHasActionErrors(['motivo' => 'required']);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('cancelar', data: ['motivo' => 'Vehículo averiado'])
        ->assertHasNoActionErrors()
        ->assertNotified('Viaje cancelado');

    expect($viaje->fresh())->estado->toBe(EstadoViaje::Cancelado)->cancelado_por->toBe('admin');
});

it('muestra las reglas de negocio del servicio como notificación y no como error', function () {
    $viaje = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso]);
    $this->mock(ServicioViaje::class)
        ->shouldReceive('cancelarPorAdmin')
        ->andThrow(new ReglaNegocio('El viaje ya terminó; no se puede cancelar.'));

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('cancelar', data: ['motivo' => 'x'])
        ->assertNotified('El viaje ya terminó; no se puede cancelar.')
        ->assertNotNotified('Viaje cancelado');
});

it('si el viaje terminó con el modal abierto, la cancelación no se ejecuta', function () {
    $viaje = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso]);
    $pagina = Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->mountAction('cancelar');

    Viaje::whereKey($viaje->id)->update(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);

    $pagina->setActionData(['motivo' => 'x'])
        ->callMountedAction()
        ->assertNotNotified('Viaje cancelado');
    expect($viaje->fresh())->estado->toBe(EstadoViaje::Finalizado)->cancelado_en->toBeNull();
});

it('no ofrece cancelar ni reasignar un viaje terminado', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado]);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->assertActionHidden('cancelar')
        ->assertActionHidden('reasignar');
});

it('reasigna un inmediato a un chofer libre elegido de la lista', function () {
    $anterior = choferEnTurno();
    $libre = choferEnTurno();
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
    $viaje = Viaje::factory()->create(['chofer_id' => $anterior->id, 'estado' => EstadoViaje::EnCamino]);

    expect(array_keys(ViajeResource::choferesElegibles($viaje)))->toBe([$libre->id]);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('reasignar', data: ['chofer_id' => $libre->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Viaje reasignado');

    expect($viaje->fresh())->chofer_id->toBe($libre->id)->estado->toBe(EstadoViaje::Aceptado);
});

it('lista para una reserva solo los choferes con la franja libre', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $libre = Usuario::factory()->chofer()->create();
    $ocupado = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'));
    reservaAceptada($ocupado, Carbon::parse('2026-10-02 15:30'));

    expect(array_keys(ViajeResource::choferesElegibles($viaje)))->toBe([$libre->id]);
});

it('rechaza al chofer elegido si dejó de estar libre antes de confirmar', function () {
    $elegido = choferEnTurno();
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);
    $pagina = Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->mountAction('asignar') // sin chofer: la acción es "Asignar chofer"
        ->setActionData(['chofer_id' => $elegido->id]);

    Viaje::factory()->create(['chofer_id' => $elegido->id, 'estado' => EstadoViaje::EnCurso]);

    // El select valida contra las opciones vigentes; si igual pasara, el servicio lo rechaza con bloqueo.
    $pagina->callMountedAction()->assertHasActionErrors(['chofer_id']);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});
