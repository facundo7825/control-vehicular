<?php

use App\Enums\EstadoViaje;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Models\Alerta;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Asignador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
});

function recordatorio(Viaje $viaje): RecordarReserva
{
    return new RecordarReserva($viaje->id, $viaje->chofer_id, $viaje->programado_para->getTimestamp());
}

it('al aceptar una reserva programa los recordatorios de 24 h y 30 min y la alerta de turno', function () {
    $inicio = Carbon::parse('2026-10-03 15:00');
    $viaje = reservaBuscando(['programado_para' => $inicio]);
    $chofer = Usuario::factory()->chofer()->create();

    app(Asignador::class)->asignarReserva($viaje, $chofer);

    Queue::assertPushed(RecordarReserva::class, 2);
    foreach ([1440, 30] as $minutos) {
        Queue::assertPushed(RecordarReserva::class, fn ($job) => $job->viajeId === $viaje->id
            && $job->choferId === $chofer->id
            && $job->programadoPara === $inicio->getTimestamp()
            && $job->delay->eq($inicio->copy()->subMinutes($minutos)));
    }
    Queue::assertPushed(AlertarReservaSinTurno::class, fn ($job) => $job->viajeId === $viaje->id
        && $job->delay->eq($inicio->copy()->subMinutes(15)));
});

it('no programa el recordatorio de 24 h si ese momento ya pasó', function () {
    $viaje = reservaBuscando(['programado_para' => now()->addHours(3)]);

    app(Asignador::class)->asignarReserva($viaje, Usuario::factory()->chofer()->create());

    Queue::assertPushed(RecordarReserva::class, 1);
    Queue::assertPushed(AlertarReservaSinTurno::class, 1);
});

it('el recordatorio avisa al solicitante y al chofer con la hora local', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));

    recordatorio($viaje)->handle($this->push);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Recordatorio de reserva'])
        ->and($this->push->titulosPara($chofer))->toBe(['Recordatorio de reserva'])
        ->and($this->push->enviados[0]['cuerpo'])->toContain('02/10 12:00');
});

it('el recordatorio no hace nada si la reserva cambió desde que se programó', function (string $cambio) {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));
    $job = recordatorio($viaje);

    match ($cambio) {
        'cancelada' => $viaje->update(['estado' => EstadoViaje::Cancelado]),
        'sin chofer' => $viaje->update(['estado' => EstadoViaje::SinChofer, 'chofer_id' => null]),
        'otro chofer' => $viaje->update(['chofer_id' => Usuario::factory()->chofer()->create()->id]),
        'otra hora' => $viaje->update(['programado_para' => Carbon::parse('2026-10-02 18:00')]),
        'ya empezó' => $viaje->update(['estado' => EstadoViaje::EnCamino]),
    };
    $job->handle($this->push);

    expect($this->push->enviados)->toBe([]);
})->with(['cancelada', 'sin chofer', 'otro chofer', 'otra hora', 'ya empezó']);

it('la alerta avisa al chofer sin turno y queda registrada para el panel', function () {
    $chofer = Usuario::factory()->chofer()->create(['nombre' => 'Juan Chofer']);
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));

    (new AlertarReservaSinTurno($viaje->id, $chofer->id, $viaje->programado_para->getTimestamp()))->handle($this->push);

    $alerta = Alerta::sole();
    expect($alerta->tipo)->toBe(Alerta::RESERVA_SIN_TURNO)
        ->and($alerta->viaje_id)->toBe($viaje->id)
        ->and($alerta->chofer_id)->toBe($chofer->id)
        ->and($alerta->mensaje)->toBe('Juan Chofer no inició turno y tiene una reserva el 02/10 12:00.')
        ->and($alerta->resuelta_en)->toBeNull()
        ->and($this->push->titulosPara($chofer))->toBe(['Iniciá tu turno']);
});

it('la alerta no hace nada si el chofer ya tiene turno abierto', function () {
    $chofer = choferEnTurno();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));

    (new AlertarReservaSinTurno($viaje->id, $chofer->id, $viaje->programado_para->getTimestamp()))->handle($this->push);

    expect(Alerta::count())->toBe(0)
        ->and($this->push->enviados)->toBe([]);
});

it('la alerta no hace nada si la reserva ya no es de ese chofer', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'));
    $job = new AlertarReservaSinTurno($viaje->id, $chofer->id, $viaje->programado_para->getTimestamp());
    $viaje->update(['estado' => EstadoViaje::SinChofer, 'chofer_id' => null]);

    $job->handle($this->push);

    expect(Alerta::count())->toBe(0)
        ->and($this->push->enviados)->toBe([]);
});
