<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/intercambio', [AuthController::class, 'intercambio']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
});
