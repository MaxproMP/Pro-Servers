<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NodeProxyController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json(['status' => '🟢 Cerebro Laravel operativo.']);
});

// === MIDDLEWARE DE PROTECCIÓN (firebase.auth) ===
Route::middleware('firebase.auth')->group(function () {
    
    // Auth endpoints (ahora protegidos por firebase para procesar login/registro)
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Perfil y status
    Route::get('/user/status', function (Request $request) {
        return response()->json([
            'status' => 'ok',
            'role' => $request->user()->role,
        ], 200);
    });
    
    Route::get('/user/{id}', [UserController::class, 'perfil']);
    Route::get('/perfil/{id}', [UserController::class, 'perfil']);
    
    // Pagos
    Route::post('/checkout/{pasarela}', [CheckoutController::class, 'crearPago']);
    Route::post('/payments/{pagoId}/refund', [RefundController::class, 'solicitarReembolso']);
    Route::get('/admin/refunds', [RefundController::class, 'historial']);

    // === DELEGACIÓN A NODE.JS (Validado por Laravel) ===
    Route::any('/project/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', '.*');
    Route::any('/server/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', '.*');
    Route::any('/curseforge/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', '.*');
});

// === MIDDLEWARE ADMINISTRATIVO (admin) ===
Route::middleware(['firebase.auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/all-servers', [NodeProxyController::class, 'getAdminServers']);
    Route::get('/stats', [NodeProxyController::class, 'getAdminStats']);
});

// === WEBHOOKS (Abiertos a Internet) ===
Route::post('/pagos/webhook/astropay', [PaymentController::class, 'webhookAstroPay']);
Route::post('/webhooks/astropay', [WebhookController::class, 'webhookAstropay']);
Route::post('/internal/audit', [AuditController::class, 'store']);
