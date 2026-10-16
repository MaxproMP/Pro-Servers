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
use App\Http\Controllers\Api\TrialController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json(['status' => '🟢 Cerebro Laravel operativo.']);
});

// === MIDDLEWARE DE PROTECCIÓN (firebase.auth) ===
Route::middleware(['throttle:ip', 'firebase.auth'])->group(function () {
    
    // Auth endpoints (ahora protegidos por firebase para procesar login/registro)
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // 2FA (TOTP)
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:10,1');
    Route::post('/2fa/enable', [TwoFactorController::class, 'enable'])->middleware('throttle:10,1');
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:10,1');

    // Soporte (lado cliente)
    Route::post('/support/chat', [SupportController::class, 'chat'])->middleware('throttle:sensible');
    Route::get('/support/messages', [SupportController::class, 'messages']);

    // Perfil y status
    Route::get('/user/status', function (Request $request) {
        return response()->json([
            'status' => 'ok',
            'role' => $request->user()->role,
        ], 200);
    });
    
    // Sesión actual: rol, plan vigente y acceso premium (todo calculado en servidor).
    Route::get('/me', function (Request $request) {
        $u = $request->user();
        $sub = $u->suscripcionActiva()->with('plan')->first();
        $plan = $sub?->plan;
        $staff = in_array($u->role, ['ceo', 'admin', 'soporte'], true);

        return response()->json([
            'success' => true,
            'username' => $u->username,
            'email' => $u->email,
            'email_verified' => $request->attributes->get('email_verified') === true,
            'role' => $u->role,
            'plan' => $plan ? [
                'nombre' => $plan->nombre,
                'memoria_mb' => (int) $plan->memoria_mb,
                'cpu_cores' => (int) $plan->cpu_cores,
                'almacenamiento_gb' => (int) $plan->almacenamiento_gb,
                'max_jugadores' => $plan->max_jugadores === null ? null : (int) $plan->max_jugadores,
            ] : null,
            'en_prueba' => (bool) $sub?->enPrueba(),
            'vence' => $sub?->fecha_vencimiento?->toIso8601String(),
            'premium' => $staff || in_array($plan?->nombre, config('services.planes_premium'), true),
        ]);
    })->middleware('throttle:60,1');

    Route::get('/user/{id}', [UserController::class, 'perfil']);
    Route::get('/perfil/{id}', [UserController::class, 'perfil']);
    
    // Pagos
    Route::get('/checkout/pasarelas', [CheckoutController::class, 'pasarelas']);
    Route::post('/checkout/asistido', [CheckoutController::class, 'asistido'])->middleware('throttle:sensible');
    Route::post('/checkout/{pasarela}', [CheckoutController::class, 'crearPago'])->middleware('throttle:sensible');
    Route::get('/trial/status', [TrialController::class, 'status']);
    Route::post('/trial/start', [TrialController::class, 'start'])->middleware('throttle:5,1');
    Route::post('/payments/{pagoId}/refund', [RefundController::class, 'solicitarReembolso'])->middleware('throttle:sensible');
    Route::get('/admin/refunds', [RefundController::class, 'historial']);

    // === DELEGACIÓN A NODE.JS (Validado por Laravel) ===
    Route::post('/project/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', 'create|clone')->middleware('throttle:deploy');
    Route::any('/project/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', '.*');
    Route::any('/server/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', '.*');
    Route::any('/curseforge/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', '.*');
    Route::get('/modrinth/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', 'search|versions');

    // Gestor de archivos (plan Oro+): Laravel exige el plan y Node valida propiedad, plan y la jaula de rutas.
    Route::get('/files/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', 'list|content|download');
    Route::post('/files/{path}', [NodeProxyController::class, 'proxyToNode'])->where('path', 'save|upload|mkdir|rename|delete')->middleware('throttle:sensible');
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
    Route::post('/accounting/confirm', [AccountingController::class, 'confirm']);
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
Route::get('/plans', [PlanAdminController::class, 'publico'])->middleware('throttle:ip');

// === WEBHOOKS (Abiertos a Internet) ===
// Firma HMAC obligatoria (WEBHOOK_SECRET). Sin secret: 403 y los pagos se confirman desde Overwatch.
Route::middleware([VerifyWebhookSignature::class, 'throttle:60,1'])->group(function () {
    Route::post('/pagos/webhook/astropay', [PaymentController::class, 'webhookAstroPay']);
    Route::post('/webhooks/astropay', [PaymentController::class, 'webhookAstroPay']);
});

// Mercado Pago firma con su propio esquema (x-signature) y el pago se confirma contra su API.
Route::post('/webhooks/mercadopago', [MercadoPagoWebhookController::class, 'handle'])->middleware('throttle:120,1');
Route::post('/internal/audit', [AuditController::class, 'store']);
