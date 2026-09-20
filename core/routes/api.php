<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\UserController;

// ==========================================
// RUTAS PÚBLICAS (No requieren token previo)
// ==========================================

// Cuando Firebase aprueba el registro, el frontend le pega a esta ruta para guardar en SQL Server
Route::post('/registro', [UserController::class, 'registrar']);

// Ruta para traer los datos del cliente y armar su panel
Route::get('/perfil/{firebase_uid}', [UserController::class, 'perfil']);


// ==========================================
// RUTAS PROTEGIDAS (Requieren inicio de sesión)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {
    
    // Trae el usuario autenticado actualmente
    Route::get('/user/me', function (Request $request) {
        return response()->json($request->user());
    });
    
    // Acá vamos a agregar las rutas del CheckoutController para AstroPay más adelante
});