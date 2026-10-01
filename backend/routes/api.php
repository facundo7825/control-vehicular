<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\EtaController;
use App\Http\Controllers\LugaresController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\OfertaController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\RutaController;
use App\Http\Controllers\TurnoController;
use App\Http\Controllers\UbicacionController;
use App\Http\Controllers\ViajeController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/intercambio', [AuthController::class, 'intercambio']);

Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
    Route::get('/configuracion', ConfiguracionController::class);
    Route::get('/choferes', MapaController::class);
    Route::get('/lugares', LugaresController::class)->middleware('throttle:lugares');
    Route::get('/ruta', RutaController::class)->middleware('throttle:rutas');
    Route::get('/viajes/actual', [ViajeController::class, 'actual']);
    Route::get('/viajes/{viaje}', [ViajeController::class, 'show'])->whereNumber('viaje');
    Route::get('/viajes', [ViajeController::class, 'index']);
    Route::post('/push/token', PushController::class);
    Route::get('/viajes/{viaje}/eta', EtaController::class);
    Route::post('/viajes/{viaje}/cancelar', [ViajeController::class, 'cancelar']);

    Route::middleware('rol:solicitante,admin')->group(function () {
        Route::post('/viajes', [ViajeController::class, 'store']);
        Route::get('/reservas/disponibles', [ReservaController::class, 'disponibles']);
        Route::post('/reservas', [ReservaController::class, 'store']);
    });

    Route::middleware('rol:chofer')->group(function () {
        Route::get('/vehiculos/disponibles', [TurnoController::class, 'vehiculosDisponibles']);
        Route::get('/turnos/actual', [TurnoController::class, 'actual']);
        Route::post('/turnos', [TurnoController::class, 'iniciar']);
        Route::post('/turnos/actual/finalizar', [TurnoController::class, 'finalizar']);
        Route::post('/ubicacion', UbicacionController::class);
        Route::post('/ofertas/{oferta}/aceptar', [OfertaController::class, 'aceptar']);
        Route::post('/ofertas/{oferta}/rechazar', [OfertaController::class, 'rechazar']);
        Route::post('/viajes/{viaje}/estado', [ViajeController::class, 'avanzar']);
        Route::get('/agenda', AgendaController::class);
    });
});
