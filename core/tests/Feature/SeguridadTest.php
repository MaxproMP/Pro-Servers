<?php

use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kreait\Laravel\Firebase\Facades\Firebase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditLogService::class)->shouldIgnoreMissing();
    config(['services.daemon.secret' => 'secreto-daemon']);
    Http::fake(['*' => Http::response(['success' => true], 200)]);
});

// --- C1/C2: proxy firmado, uid fuera del alcance del navegador ---

it('el proxy firma con el secret, declara rol y plan, y nunca reenvía el uid del navegador', function () {
    $h = tokenFirebase('uid-a', 'a@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();
    conPlan('uid-a', 'oro');

    $this->postJson('/api/server/start', ['serverId' => 'srv-x', 'uid' => 'uid-victima'], $h)->assertOk();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/server/start')
        && $r->hasHeader('x-daemon-secret', 'secreto-daemon')
        && $r->hasHeader('x-staff-role', 'usuario')
        && $r->hasHeader('x-plan', 'oro')
        && ! isset($r['uid']) && $r['serverId'] === 'srv-x');
});

it('sin secret configurado el proxy queda cerrado', function () {
    config(['services.daemon.secret' => '']);
    $h = tokenFirebase('uid-a', 'a@gmail.com');

    $this->postJson('/api/server/start', ['serverId' => 'srv-x'], $h)->assertStatus(503);
    Http::assertNothingSent();
});

it('el navegador no puede inyectar límites de plan en el despliegue', function () {
    $h = tokenFirebase('uid-a', 'a@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();

    $this->postJson('/api/project/create', ['edition' => 'java', 'plan' => ['memoria_mb' => 999999]], $h)->assertOk();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/project/create') && $r['plan'] === null);
});

it('desplegar exige email verificado', function () {
    $h = tokenFirebase('uid-nv', 'nv@gmail.com', false);

    $this->postJson('/api/project/create', ['edition' => 'java'], $h)->assertStatus(403);
    Http::assertNothingSent();
});

// --- A2: Mine AI desde Plan Oro ---

it('Mine AI se bloquea en servidor para planes menores a Oro', function () {
    $h = tokenFirebase('uid-r', 'r@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();
    conPlan('uid-r', 'cobre');

    $this->postJson('/api/server/mine-ai', ['serverId' => 'srv-r'], $h)->assertStatus(403);
    Http::assertNothingSent();
});

it('Mine AI pasa con Plan Oro o con rol de staff', function () {
    $h = tokenFirebase('uid-o', 'o@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();
    conPlan('uid-o', 'oro');
    $this->postJson('/api/server/mine-ai', ['serverId' => 'srv-o'], $h)->assertOk();

    $h2 = tokenFirebase('uid-s', 's@gmail.com');
    $this->postJson('/api/login', [], $h2)->assertOk();
    Cliente::where('firebase_uid', 'uid-s')->update(['role' => 'soporte']);
    $this->postJson('/api/server/mine-ai', ['serverId' => 'srv-s'], $h2)->assertOk();

    Http::assertSentCount(2);
});

it('el gestor de archivos queda cerrado en servidor hasta su lanzamiento', function () {
    $h = tokenFirebase('uid-f', 'f@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();
    $this->getJson('/api/files/list?serverId=srv-f', $h)->assertStatus(403);

    conPlan('uid-f', 'diamante');
    $this->getJson('/api/files/list?serverId=srv-f', $h)->assertStatus(501);
    Http::assertNothingSent();
});

// --- A3: IDOR de perfiles ---

it('cada cuenta lee solo su propio perfil', function () {
    $hb = tokenFirebase('uid-b', 'b@gmail.com');
    $this->postJson('/api/login', [], $hb)->assertOk();
    $ha = tokenFirebase('uid-a', 'a@gmail.com');
    $this->postJson('/api/login', [], $ha)->assertOk();

    $this->getJson('/api/perfil/uid-a', $ha)->assertOk()->assertJson(['email' => 'a@gmail.com']);
    $this->getJson('/api/perfil/uid-b', $ha)->assertStatus(403);
    $this->getJson('/api/user/uid-b', $ha)->assertStatus(403);
});

it('el staff puede leer perfiles ajenos', function () {
    $hb = tokenFirebase('uid-b', 'b@gmail.com');
    $this->postJson('/api/login', [], $hb)->assertOk();
    $hs = tokenFirebase('uid-s', 's@gmail.com');
    $this->postJson('/api/login', [], $hs)->assertOk();
    Cliente::where('firebase_uid', 'uid-s')->update(['role' => 'admin']);

    $this->getJson('/api/perfil/uid-b', $hs)->assertOk()->assertJson(['email' => 'b@gmail.com']);
});

// --- C3: webhooks de pago ---

it('sin WEBHOOK_SECRET ningún webhook activa suscripciones', function () {
    config(['services.webhooks.secret' => null]);
    $c = Cliente::create(['firebase_uid' => 'uid-w', 'email' => 'w@gmail.com', 'username' => 'w', 'nombre' => 'W', 'password' => 'x', 'role' => 'usuario', 'plan_activo' => 'ninguno']);

    $this->postJson('/api/webhooks/astropay', ['status' => 'APPROVED', 'user_id' => 'uid-w', 'plan_name' => 'ghost-warrior', 'amount' => 0.01, 'merchant_deposit_id' => 'FAKE'])
        ->assertStatus(403);
    $this->postJson('/api/pagos/webhook/astropay', ['status' => 'APPROVED', 'merchant_invoice_id' => 'PRO-1'])->assertStatus(403);

    expect(Suscripcion::where('cliente_id', $c->id)->count())->toBe(0);
});

it('con secret configurado rechaza firmas inválidas y acepta las válidas', function () {
    config(['services.webhooks.secret' => 'whsec']);
    $c = Cliente::create(['firebase_uid' => 'uid-w', 'email' => 'w@gmail.com', 'username' => 'w', 'nombre' => 'W', 'password' => 'x', 'role' => 'usuario', 'plan_activo' => 'ninguno']);
    $sub = Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => Plan::where('nombre', 'oro')->value('id'), 'estado' => 'pendiente', 'ciclo_meses' => 1]);
    Pago::create(['suscripcion_id' => $sub->id, 'monto' => 15, 'pasarela_pago' => 'astropay', 'medio_pago' => 'no_definido', 'estado' => 'pendiente', 'transaccion_id' => 'PRO-OK']);
    $body = json_encode(['status' => 'APPROVED', 'merchant_invoice_id' => 'PRO-OK']);

    $this->call('POST', '/api/pagos/webhook/astropay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => 'firma-falsa'], $body)->assertStatus(403);
    expect($sub->fresh()->estado)->toBe('pendiente');

    $this->call('POST', '/api/pagos/webhook/astropay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'whsec')], $body)->assertOk();
    expect($sub->fresh()->estado)->toBe('activa');
});

// --- Bug de checkout: el uid de Firebase llega al controlador ---

it('el checkout encuentra al cliente autenticado y registra el pago pendiente', function () {
    Http::fake(['api.mercadopago.com/*' => Http::response(['init_point' => 'https://mp.test/pay'], 201)]);
    $h = tokenFirebase('uid-c', 'c@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();

    $this->postJson('/api/checkout/mercadopago', ['plan_nombre' => 'hierro', 'ciclo_meses' => 3], $h)->assertOk();

    $pago = Pago::firstOrFail();
    expect((float) $pago->monto)->toBe(24.3)->and($pago->estado)->toBe('pendiente');
});

it('la prueba gratis exige email verificado', function () {
    $h = tokenFirebase('uid-t', 't@gmail.com', false);
    $this->postJson('/api/trial/start', ['plan_nombre' => 'redstone'], $h)->assertStatus(403);
    expect(Suscripcion::count())->toBe(0);
});
