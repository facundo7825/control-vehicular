<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/intercambio', [AuthController::class, 'intercambio']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
    Route::get('/configuracion', \App\Http\Controllers\ConfiguracionController::class);

    Route::middleware('rol:chofer')->group(function () {
        Route::get('/vehiculos/disponibles', [\App\Http\Controllers\TurnoController::class, 'vehiculosDisponibles']);
        Route::get('/turnos/actual', [\App\Http\Controllers\TurnoController::class, 'actual']);
        Route::post('/turnos', [\App\Http\Controllers\TurnoController::class, 'iniciar']);
        Route::post('/turnos/actual/finalizar', [\App\Http\Controllers\TurnoController::class, 'finalizar']);
        Route::post('/ubicacion', \App\Http\Controllers\UbicacionController::class);
    });
});
