<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API ROUTES — ProServers Cerebro (Laravel)
|--------------------------------------------------------------------------
| Prefijo automático: /api/
| Todas las respuestas son JSON. Sin sesiones ni cookies.
*/

// === HEALTH CHECK ===
Route::get('/ping', function () {
    return response()->json(['status' => '🟢 Cerebro Laravel operativo.']);
});

// === AUTH (Firebase UID → Base de datos) ===
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/login', [AuthController::class, 'login']);

// === PAGOS Y WEBHOOKS ===
Route::post('/checkout/payment', [CheckoutController::class, 'crearPago']);
Route::post('/pagos/webhook/astropay', [PaymentController::class, 'webhookAstroPay']);
Route::post('/webhooks/astropay', [WebhookController::class, 'webhookAstropay']);

// === ESTADO / ROL DEL USUARIO ===
// El panel admin.html consulta esta ruta para verificar permisos.
// Devuelve el rol del usuario: 'ceo', 'soporte', 'cliente', etc.
Route::get('/user/status', function (Request $request) {
    $uid = $request->query('uid');

    if (! $uid) {
        return response()->json(['error' => 'UID requerido'], 400);
    }

    $cliente = Cliente::where('firebase_uid', $uid)->first();

    if (! $cliente) {
        // Si no está en la base de datos todavía, devolvemos rol null
        // para que el frontend solo deje pasar al CEO hardcodeado.
        return response()->json([
            'status' => 'not_found',
            'role' => null,
        ], 200);
    }

    return response()->json([
        'status' => 'ok',
        'role' => $cliente->role,
    ], 200);
});

// === PERFIL DEL USUARIO ===
Route::get('/user/{firebase_uid}', [UserController::class, 'perfil']);
