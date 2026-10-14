<?php

use App\Http\Controllers\Api\Admin\AccountingController;
use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\OverviewController;
use App\Http\Controllers\Api\Admin\PlanAdminController;
use App\Http\Controllers\Api\Admin\SystemController;
use App\Http\Controllers\Api\Admin\TicketAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NodeProxyController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TwoFactorController;
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

    // 2FA (TOTP)
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/2fa/enable', [TwoFactorController::class, 'enable'])->middleware('throttle:10,1');
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:10,1');

    // Soporte (lado cliente)
    Route::post('/support/chat', [SupportController::class, 'chat']);
    Route::get('/support/messages', [SupportController::class, 'messages']);

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

// === PANEL OVERWATCH ===
// Nivel 1: ceo, admin y soporte. Nivel 2: ceo y admin. Nivel 3: solo ceo.
Route::middleware(['firebase.auth', 'admin:ceo,admin,soporte'])->prefix('admin')->group(function () {
    Route::get('/stats', [OverviewController::class, 'stats']);
    Route::get('/dashboard-stats', [OverviewController::class, 'stats']);
    Route::get('/telemetry', [OverviewController::class, 'telemetry']);
    Route::get('/all-servers', [OverviewController::class, 'telemetry']);
    Route::get('/audit', [OverviewController::class, 'audit']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::get('/clients/{id}', [ClientController::class, 'show'])->whereNumber('id');
    Route::post('/clients/{id}/recovery', [ClientController::class, 'recovery'])->whereNumber('id')->middleware('throttle:20,1');

    Route::post('/server-action', [SystemController::class, 'serverAction']);
    Route::get('/staff/list', [StaffController::class, 'list']);

    Route::get('/tickets', [TicketAdminController::class, 'index']);
    Route::get('/tickets/{id}', [TicketAdminController::class, 'show'])->whereNumber('id');
    Route::post('/tickets/{id}/reply', [TicketAdminController::class, 'reply'])->whereNumber('id');
    Route::post('/tickets/{id}/close', [TicketAdminController::class, 'close'])->whereNumber('id');
    Route::post('/tickets/{id}/diagnose', [TicketAdminController::class, 'diagnose'])->whereNumber('id');
    Route::get('/tickets/{id}/pdf', [TicketAdminController::class, 'pdf'])->whereNumber('id');

    // Compatibilidad con el soporte anterior
    Route::get('/support-tickets', [SupportController::class, 'adminTickets']);
    Route::post('/support-reply', [SupportController::class, 'adminReply']);
    Route::post('/close-ticket', [SupportController::class, 'adminClose']);
});

Route::middleware(['firebase.auth', 'admin:ceo,admin'])->prefix('admin')->group(function () {
    Route::patch('/clients/{id}', [ClientController::class, 'update'])->whereNumber('id');
    Route::post('/clients/{id}/plan', [ClientController::class, 'setPlan'])->whereNumber('id');
    Route::post('/clients/{id}/compensate', [ClientController::class, 'compensate'])->whereNumber('id');
    Route::post('/clients/{id}/freeze', [ClientController::class, 'freeze'])->whereNumber('id');
    Route::delete('/clients/{id}', [ClientController::class, 'destroy'])->whereNumber('id');

    Route::get('/accounting', [AccountingController::class, 'summary']);
    Route::post('/accounting/payment', [AccountingController::class, 'payment']);
    Route::post('/accounting/refund', [AccountingController::class, 'refund']);
    Route::get('/payments/{id}/pdf', [AccountingController::class, 'pdf'])->whereNumber('id');

    Route::get('/plans', [PlanAdminController::class, 'index']);
    Route::patch('/plans/{id}', [PlanAdminController::class, 'update'])->whereNumber('id');

    Route::post('/system-prune', [SystemController::class, 'prune']);
    Route::post('/global-broadcast', [SystemController::class, 'broadcast']);
});

Route::middleware(['firebase.auth', 'admin:ceo'])->prefix('admin')->group(function () {
    Route::post('/staff/add', [StaffController::class, 'add']);
    Route::post('/staff/remove', [StaffController::class, 'remove']);
    Route::post('/system/sync-mongo', [SystemController::class, 'syncMongo']);
    Route::post('/system/purge-seed', [SystemController::class, 'purgeSeed']);
});

// Catálogo público (solo planes visibles)
Route::get('/plans', [PlanAdminController::class, 'publico']);

// === WEBHOOKS (Abiertos a Internet) ===
Route::post('/pagos/webhook/astropay', [PaymentController::class, 'webhookAstroPay']);
Route::post('/webhooks/astropay', [WebhookController::class, 'webhookAstropay']);
Route::post('/internal/audit', [AuditController::class, 'store']);
