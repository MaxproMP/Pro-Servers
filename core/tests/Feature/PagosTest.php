<?php

use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditLogService::class)->shouldIgnoreMissing();
    config([
        'app.url' => 'https://proservers.test',
        'services.daemon.secret' => 's',
        'services.mercadopago.token' => 'TEST-token',
        'services.mercadopago.webhook_secret' => 'mp-secret',
        'services.mercadopago.tasa_ars' => 1000,
        'services.astropay' => ['api_url' => null, 'client_id' => null, 'secret' => null], // no depender del .env real
    ]);
    $this->h = tokenFirebase('uid-pay', 'pay@gmail.com');
    $this->postJson('/api/login', [], $this->h)->assertOk();
});

/** Notificación de MP firmada como la firma Mercado Pago (manifiesto id/request-id/ts). */
function notificarMp(string $paymentId, string $secret = 'mp-secret')
{
    $ts = '1700000000';
    $firma = hash_hmac('sha256', "id:{$paymentId};request-id:req-1;ts:{$ts};", $secret);

    return test()->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => $paymentId]], [
        'x-signature' => "ts={$ts},v1={$firma}", 'x-request-id' => 'req-1',
    ]);
}

function iniciarMp(int $ciclo = 3): Pago
{
    Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['init_point' => 'https://mp.test/pay'], 201)]);
    test()->postJson('/api/checkout/mercadopago', ['plan_nombre' => 'oro', 'ciclo_meses' => $ciclo], test()->h)
        ->assertOk()->assertJson(['redirect_url' => 'https://mp.test/pay', 'monto_usd' => 40.5, 'monto_ars' => 40500]);

    return Pago::latest('id')->firstOrFail();
}

it('informa qué pasarelas están configuradas de verdad', function () {
    $this->getJson('/api/checkout/pasarelas', $this->h)->assertOk()
        ->assertJson(['pasarelas' => ['mercadopago' => true, 'astropay' => false, 'uala' => false], 'tasa_ars' => 1000]);
});

it('una pasarela sin integración no crea pagos huérfanos', function () {
    $this->postJson('/api/checkout/uala', ['plan_nombre' => 'oro', 'ciclo_meses' => 1], $this->h)->assertStatus(503);
    $this->postJson('/api/checkout/astropay', ['plan_nombre' => 'oro', 'ciclo_meses' => 1], $this->h)->assertStatus(503);
    expect(Pago::count())->toBe(0);
});

it('solo acepta los ciclos con descuento publicados', function () {
    $this->postJson('/api/checkout/mercadopago', ['plan_nombre' => 'oro', 'ciclo_meses' => 2], $this->h)->assertStatus(422);
});

it('la preferencia de MP cobra el monto calculado en servidor y apunta al webhook', function () {
    $pago = iniciarMp(3);

    expect((float) $pago->monto)->toBe(40.5)->and($pago->estado)->toBe('pendiente');
    Http::assertSent(fn ($r) => $r['items'][0]['unit_price'] == 40500.0 && $r['items'][0]['currency_id'] === 'ARS'
        && $r['external_reference'] === $pago->transaccion_id
        && $r['notification_url'] === 'https://proservers.test/api/webhooks/mercadopago');
});

it('si MP falla, el pago queda fallido y la suscripción cancelada', function () {
    Http::fake(['api.mercadopago.com/*' => Http::response([], 500)]);
    $this->postJson('/api/checkout/mercadopago', ['plan_nombre' => 'oro', 'ciclo_meses' => 1], $this->h)->assertStatus(502);

    expect(Pago::firstOrFail()->estado)->toBe('fallido')->and(Suscripcion::firstOrFail()->estado)->toBe('cancelada');
});

it('el webhook de MP rechaza firmas inválidas', function () {
    $pago = iniciarMp();
    notificarMp('123', 'otro-secret')->assertStatus(403);
    expect($pago->fresh()->estado)->toBe('pendiente');
});

it('un pago aprobado en MP activa la suscripción por su ciclo, una sola vez', function () {
    $pago = iniciarMp(3);
    Http::fake([
        'api.mercadopago.com/v1/payments/987' => Http::response(['status' => 'approved', 'external_reference' => $pago->transaccion_id, 'transaction_amount' => 40500, 'currency_id' => 'ARS', 'payment_type_id' => 'credit_card']),
        '*' => Http::response(['success' => true]),
    ]);

    notificarMp('987')->assertOk();
    $sub = $pago->suscripcion->fresh();
    expect($pago->fresh()->estado)->toBe('completado')->and($pago->fresh()->medio_pago)->toBe('tarjeta')
        ->and($sub->estado)->toBe('activa')
        ->and((int) round(now()->diffInMonths($sub->fecha_vencimiento)))->toBe(3);

    $vence = $sub->fecha_vencimiento;
    notificarMp('987')->assertOk(); // reintento de MP
    expect($sub->fresh()->fecha_vencimiento->equalTo($vence))->toBeTrue();
});

it('un pago aprobado por menos de lo esperado no activa nada', function () {
    $pago = iniciarMp(3);
    Http::fake(['api.mercadopago.com/v1/payments/55' => Http::response(['status' => 'approved', 'external_reference' => $pago->transaccion_id, 'transaction_amount' => 100, 'currency_id' => 'ARS'])]);

    notificarMp('55')->assertStatus(422);
    expect($pago->fresh()->estado)->toBe('pendiente')->and($pago->suscripcion->fresh()->estado)->toBe('pendiente');
});

it('confirmar un pago manual desde el Panel Admin activa la suscripción y reemplaza la prueba', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    $this->postJson('/api/trial/start', ['plan_nombre' => 'redstone'], $this->h)->assertCreated();
    $c = Cliente::where('firebase_uid', 'uid-pay')->firstOrFail();
    $sub = Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => Plan::where('nombre', 'oro')->value('id'), 'estado' => 'pendiente', 'ciclo_meses' => 1]);

    $hs = tokenFirebase('uid-ceo', config('services.ceo_email'));
    $this->postJson('/api/login', [], $hs)->assertOk();
    $this->postJson('/api/admin/accounting/payment', ['suscripcion_id' => $sub->id, 'monto' => 15, 'medio_pago' => 'transferencia'], $hs)->assertCreated();

    expect($sub->fresh()->estado)->toBe('activa')
        ->and(Suscripcion::whereNotNull('trial_ends_at')->first()->estado)->toBe('reemplazada')
        ->and($c->fresh()->suscripcionActiva->id)->toBe($sub->id);
});

it('el pago asistido deja la suscripción pendiente, abre un ticket y no duplica pedidos', function () {
    $r = $this->postJson('/api/checkout/asistido', ['plan_nombre' => 'oro', 'ciclo_meses' => 3, 'medio' => 'uala'], $this->h)
        ->assertCreated()->assertJson(['monto_usd' => 40.5, 'medio' => 'Ualá Bis']);
    $this->postJson('/api/checkout/asistido', ['plan_nombre' => 'oro', 'ciclo_meses' => 3, 'medio' => 'uala'], $this->h)
        ->assertCreated()->assertJson(['transaccion_id' => $r->json('transaccion_id')]);

    $pago = Pago::firstOrFail();
    expect(Pago::count())->toBe(1)
        ->and($pago->estado)->toBe('pendiente')->and($pago->pasarela_pago)->toBe('uala')
        ->and($pago->suscripcion->estado)->toBe('pendiente')
        ->and(\App\Models\MensajeTicket::where('mensaje', 'like', '%'.$pago->transaccion_id.'%')->count())->toBe(1);
});

it('el pago asistido valida plan, ciclo y medio', function () {
    $this->postJson('/api/checkout/asistido', ['plan_nombre' => 'oro', 'ciclo_meses' => 3, 'medio' => 'bitcoin'], $this->h)->assertStatus(422);
    $this->postJson('/api/checkout/asistido', ['plan_nombre' => 'inexistente', 'ciclo_meses' => 1, 'medio' => 'uala'], $this->h)->assertStatus(422);
    expect(Pago::count())->toBe(0);
});

it('el Panel Admin confirma un pago asistido una sola vez y activa el plan por su ciclo', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    $this->postJson('/api/checkout/asistido', ['plan_nombre' => 'oro', 'ciclo_meses' => 6, 'medio' => 'transferencia'], $this->h)->assertCreated();
    $pago = Pago::firstOrFail();

    $this->postJson('/api/admin/accounting/confirm', ['pago_id' => $pago->id, 'medio_pago' => 'transferencia'], $this->h)->assertForbidden();

    $hs = tokenFirebase('uid-ceo', config('services.ceo_email'));
    $this->postJson('/api/login', [], $hs)->assertOk();
    $this->postJson('/api/admin/accounting/confirm', ['pago_id' => $pago->id, 'medio_pago' => 'transferencia'], $hs)->assertOk();
    $this->postJson('/api/admin/accounting/confirm', ['pago_id' => $pago->id, 'medio_pago' => 'transferencia'], $hs)->assertStatus(422);

    $sub = $pago->suscripcion->fresh();
    expect($pago->fresh()->estado)->toBe('completado')->and($sub->estado)->toBe('activa')
        ->and((int) round(now()->diffInMonths($sub->fecha_vencimiento)))->toBe(6);
});

it('pagar con tarjeta usa el checkout de Mercado Pago limitado a tarjetas y lo registra como tal', function () {
    Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['init_point' => 'https://mp.test/pay'], 201)]);

    $this->postJson('/api/checkout/tarjeta', ['plan_nombre' => 'oro', 'ciclo_meses' => 1], $this->h)
        ->assertOk()->assertJsonPath('redirect_url', 'https://mp.test/pay');

    Http::assertSent(fn ($r) => $r['payment_methods']['excluded_payment_types'] === [['id' => 'ticket'], ['id' => 'atm']]);
    $pago = Pago::latest('id')->first();
    expect([$pago->pasarela_pago, $pago->medio_pago, $pago->estado])->toBe(['mercadopago', 'tarjeta', 'pendiente']);
});

it('Google Pay queda asistido (sin pasarela nativa) y se registra con medio tarjeta', function () {
    $this->getJson('/api/checkout/pasarelas', $this->h)->assertJsonPath('pasarelas.googlepay', false)->assertJsonPath('pasarelas.tarjeta', true);
    $this->postJson('/api/checkout/googlepay', ['plan_nombre' => 'oro', 'ciclo_meses' => 1], $this->h)->assertStatus(503);

    $this->postJson('/api/checkout/asistido', ['plan_nombre' => 'oro', 'ciclo_meses' => 1, 'medio' => 'googlepay'], $this->h)
        ->assertCreated()->assertJsonPath('medio', 'Google Pay');
    expect(Pago::latest('id')->first()->medio_pago)->toBe('tarjeta');
});
