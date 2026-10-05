<?php

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Filament\Widgets\PedidosPorHora;
use App\Filament\Widgets\ResumenOperativo;
use App\Filament\Widgets\ViajesPorDia;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\EstadisticasPanel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// 2026-10-01 12:00 UTC = 09:00 en Buenos Aires; el día local 1/10 va de 03:00 UTC a 03:00 UTC del 2/10.
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

it('cuenta los viajes pedidos hoy en hora local, con finalizados y cancelados', function () {
    Viaje::factory()->create(['created_at' => Carbon::parse('2026-10-01 03:30:00')]); // 00:30 local de hoy
    Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);
    Viaje::factory()->create(['estado' => EstadoViaje::Cancelado, 'cancelado_en' => now()]);
    Viaje::factory()->create(['created_at' => Carbon::parse('2026-10-01 02:30:00')]); // 23:30 local de ayer
    Viaje::factory()->create(['created_at' => Carbon::parse('2026-10-02 03:30:00')]); // mañana local

    expect(app(EstadisticasPanel::class)->viajesHoy())
        ->toBe(['total' => 3, 'finalizados' => 1, 'cancelados' => 1]);
});

it('promedia la espera del pedido a "llegó" en los inmediatos que llegaron hoy', function () {
    Viaje::factory()->create([
        'created_at' => now()->subMinutes(10), 'llego_en' => now(), 'estado' => EstadoViaje::Llego,
    ]);
    Viaje::factory()->create([
        'created_at' => now()->subMinutes(25), 'llego_en' => now()->subMinutes(5), 'estado' => EstadoViaje::EnCurso,
    ]);
    // Reserva: no cuenta aunque haya llegado hoy.
    reservaBuscando(['created_at' => now()->subDay(), 'llego_en' => now(), 'estado' => EstadoViaje::Llego]);
    // Llegó a las 23:00 locales de ayer (02:00 UTC de hoy): no cuenta.
    Viaje::factory()->create([
        'created_at' => Carbon::parse('2026-10-01 01:00:00'), 'llego_en' => Carbon::parse('2026-10-01 02:00:00'),
        'estado' => EstadoViaje::Finalizado,
    ]);

    expect(app(EstadisticasPanel::class)->esperaPromedioHoy())->toBe(15.0);
});

it('sin llegadas hoy la espera promedio es nula y el tablero muestra "—"', function () {
    expect(app(EstadisticasPanel::class)->esperaPromedioHoy())->toBeNull();

    Livewire::test(ResumenOperativo::class)
        ->assertSee('Viajes hoy')
        ->assertSee('Espera promedio hoy')
        ->assertSee('—')
        ->assertSee('Choferes en turno');
});

it('cuenta los choferes en turno, libres y en viaje', function () {
    choferEnTurno(); // libre
    $enViaje = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $enViaje->id, 'estado' => EstadoViaje::EnCurso]);
    choferEnTurno(minutos: 30); // sin señal: en turno, pero ni libre ni en viaje

    expect(app(EstadisticasPanel::class)->choferesEnTurno())
        ->toBe(['total' => 3, 'libres' => 1, 'en_viaje' => 1]);
});

it('muestra los números del día en el tablero', function () {
    Viaje::factory()->create([
        'created_at' => now()->subMinutes(12), 'llego_en' => now(), 'estado' => EstadoViaje::Finalizado,
        'finalizado_en' => now(),
    ]);
    choferEnTurno();

    Livewire::test(ResumenOperativo::class)
        ->assertSee('Viajes hoy')
        ->assertSee('1 finalizado · 0 cancelados')->assertDontSee('1 finalizados')
        ->assertSee('12 min')
        ->assertDontSee('—')
        ->assertSee('1 libre · 0 en viaje')->assertDontSee('1 libres')
        ->assertSee('Alertas sin resolver');
});

it('arma la serie de viajes por día de los últimos 14 días locales', function () {
    Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);
    Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(13)]);
    // 02:00 UTC del 1/10 son las 23:00 del 30/9 en hora local.
    Viaje::factory()->create(['estado' => EstadoViaje::Cancelado, 'cancelado_en' => Carbon::parse('2026-10-01 02:00:00')]);
    // Sin chofer: el día es el de updated_at (el momento de la transición).
    Viaje::factory()->create(['estado' => EstadoViaje::SinChofer, 'updated_at' => now()]);
    // Fuera de la ventana.
    Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(14)]);
    Viaje::factory()->create(['estado' => EstadoViaje::SinChofer, 'updated_at' => now()->subDays(20)]);

    $serie = app(EstadisticasPanel::class)->viajesPorDia();

    expect($serie['etiquetas'])->toHaveCount(14)
        ->and($serie['etiquetas'][0])->toBe('18/09')
        ->and($serie['etiquetas'][13])->toBe('01/10')
        ->and($serie['finalizados'])->toBe([1, ...array_fill(0, 12, 0), 1])
        ->and($serie['cancelados'])->toBe([...array_fill(0, 12, 0), 1, 0])
        ->and($serie['sin_chofer'])->toBe([...array_fill(0, 13, 0), 1]);
});

it('arma la serie de pedidos por hora local de los últimos 30 días', function () {
    Viaje::factory()->create(); // 12:00 UTC = 09 local
    Viaje::factory()->create(['created_at' => now()->subDays(3)]); // 09 local
    Viaje::factory()->create(['created_at' => Carbon::parse('2026-10-01 02:30:00')]); // 23 local del 30/9
    Viaje::factory()->create(['created_at' => now()->subDays(31)]); // fuera de la ventana

    $serie = app(EstadisticasPanel::class)->pedidosPorHora();

    $esperado = array_fill(0, 24, 0);
    $esperado[9] = 2;
    $esperado[23] = 1;

    expect($serie)->toBe($esperado);
});

it('los gráficos se dibujan con sus series', function () {
    Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now(), 'tipo' => TipoViaje::Inmediato]);

    Livewire::test(ViajesPorDia::class)
        ->assertOk()
        ->assertSee('Viajes por día')
        ->assertSee('Finalizados')
        ->assertSee('Sin chofer');

    Livewire::test(PedidosPorHora::class)
        ->assertOk()
        ->assertSee('Pedidos por hora del día')
        ->assertSee('09 h');

    $this->get('/admin')->assertOk()
        ->assertSeeLivewire(ViajesPorDia::class)
        ->assertSeeLivewire(PedidosPorHora::class);
});

it('el resumen usa plural salvo para uno', function () {
    foreach (range(1, 2) as $i) {
        Viaje::factory()->create(['estado' => EstadoViaje::Cancelado, 'cancelado_en' => now()]);
    }
    choferEnTurno();
    choferEnTurno();

    Livewire::test(ResumenOperativo::class)
        ->assertSee('0 finalizados · 2 cancelados')
        ->assertSee('2 libres · 0 en viaje');

    Viaje::factory()->create(['estado' => EstadoViaje::Cancelado, 'cancelado_en' => now()]);
    Viaje::query()->where('estado', EstadoViaje::Cancelado)->limit(2)->delete();

    Livewire::test(ResumenOperativo::class)->assertSee('0 finalizados · 1 cancelado')->assertDontSee('1 cancelados');
});
