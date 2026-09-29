<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/intercambio', [AuthController::class, 'intercambio']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
    Route::get('/configuracion', \App\Http\Controllers\ConfiguracionController::class);
    Route::get('/choferes', \App\Http\Controllers\MapaController::class);
    Route::get('/viajes/actual', [\App\Http\Controllers\ViajeController::class, 'actual']);
    Route::post('/push/token', \App\Http\Controllers\PushController::class);
    Route::post('/viajes/{viaje}/cancelar', [\App\Http\Controllers\ViajeController::class, 'cancelar']);

    Route::middleware('rol:solicitante,admin')->group(function () {
        Route::post('/viajes', [\App\Http\Controllers\ViajeController::class, 'store']);
        Route::get('/reservas/disponibles', [\App\Http\Controllers\ReservaController::class, 'disponibles']);
        Route::post('/reservas', [\App\Http\Controllers\ReservaController::class, 'store']);
    });

    Route::middleware('rol:chofer')->group(function () {
        Route::get('/vehiculos/disponibles', [\App\Http\Controllers\TurnoController::class, 'vehiculosDisponibles']);
        Route::get('/turnos/actual', [\App\Http\Controllers\TurnoController::class, 'actual']);
        Route::post('/turnos', [\App\Http\Controllers\TurnoController::class, 'iniciar']);
        Route::post('/turnos/actual/finalizar', [\App\Http\Controllers\TurnoController::class, 'finalizar']);
        Route::post('/ubicacion', \App\Http\Controllers\UbicacionController::class);
        Route::post('/ofertas/{oferta}/aceptar', [\App\Http\Controllers\OfertaController::class, 'aceptar']);
        Route::post('/ofertas/{oferta}/rechazar', [\App\Http\Controllers\OfertaController::class, 'rechazar']);
        Route::post('/viajes/{viaje}/estado', [\App\Http\Controllers\ViajeController::class, 'avanzar']);
    });
});
