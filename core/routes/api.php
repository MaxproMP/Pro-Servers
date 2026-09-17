<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;

// Rutas públicas para que tu HTML de Node mande los POST
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']); // Agregado para el Sprint 7

// Rutas protegidas con middlewares (Sprint 6) - Solo responden si tenés el token
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', function (Request $request) {
        return response()->json($request->user());
    });
    
    // Acá agregaremos más adelante la ruta para comprar/pagar servidores
});
