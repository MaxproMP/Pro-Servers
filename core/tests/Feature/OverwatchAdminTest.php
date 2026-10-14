<?php

use App\Http\Middleware\FirebaseTokenMiddleware;
use App\Mail\RecoveryMail;
use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Kreait\Laravel\Firebase\Facades\Firebase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(FirebaseTokenMiddleware::class);
    // MongoDB no existe en la suite de pruebas: la auditoría se simula.
    $this->mock(AuditLogService::class)->shouldReceive('record')->andReturn(new \App\Models\AuditEvent());
});

function cliente(string $rol = 'usuario', array $extra = []): Cliente
{
    static $n = 0;
    $n++;

    return Cliente::create($extra + [
        'firebase_uid' => "uid-$n", 'email' => "c$n@test.com", 'username' => "user$n",
        'nombre' => "Nombre$n", 'password' => 'x', 'role' => $rol, 'plan_activo' => 'ninguno',
    ]);
}

function conPlanActivo(Cliente $c, string $plan = 'redstone', float $precio = 6.0): Suscripcion
{
    $p = Plan::firstOrCreate(['nombre' => $plan], ['precio_mensual' => $precio, 'memoria_mb' => 4096, 'cpu_cores' => 2, 'almacenamiento_gb' => 20]);

    return Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => $p->id, 'estado' => 'activa', 'ciclo_meses' => 1, 'fecha_inicio' => now(), 'fecha_vencimiento' => now()->addMonth()]);
}

it('lista a todos los clientes aunque no tengan servidor ni plan', function () {
    cliente('usuario', ['email' => 'sinplan@test.com']);
    conPlanActivo(cliente('usuario', ['email' => 'conplan@test.com']));

    $res = $this->actingAs(cliente('admin'))->getJson('/api/admin/clients')->assertOk();

    $sinPlan = collect($res->json('clients'))->firstWhere('email', 'sinplan@test.com');
    expect($sinPlan['plan'])->toBeNull()->and($sinPlan['server'])->toBeNull()
        ->and($sinPlan['has_2fa'])->toBeFalse()->and($sinPlan['estado'])->toBe('activo');
    expect(collect($res->json('clients'))->firstWhere('email', 'conplan@test.com')['plan']['nombre'])->toBe('redstone');
});

it('el recovery de 2FA limpia el secreto y avisa por correo', function () {
    Mail::fake();
    $juan = cliente('usuario', ['google2fa_secret' => Crypt::encryptString('SECRETO')]);

    $this->actingAs(cliente('soporte'))
        ->postJson("/api/admin/clients/{$juan->id}/recovery", ['mode' => 'reset_2fa'])
        ->assertOk()->assertJson(['2fa_reseteado' => true, 'correo_enviado' => true]);

    expect($juan->fresh()->google2fa_secret)->toBeNull();
    Mail::assertSent(RecoveryMail::class, fn ($m) => $m->hasTo($juan->email) && $m->modo === 'reset_2fa');
});

it('el full_recovery genera enlace de Firebase y lo envía', function () {
    Mail::fake();
    $auth = Mockery::mock();
    $auth->shouldReceive('generatePasswordResetLink')->once()->andReturn('https://firebase.test/reset');
    Firebase::shouldReceive('auth')->andReturn($auth);
    $juan = cliente('usuario', ['google2fa_secret' => Crypt::encryptString('S')]);

    $this->actingAs(cliente('admin'))
        ->postJson("/api/admin/clients/{$juan->id}/recovery", ['mode' => 'full_recovery'])
        ->assertOk()->assertJson(['enlace_generado' => true, '2fa_reseteado' => true]);

    Mail::assertSent(RecoveryMail::class, fn ($m) => $m->resetLink === 'https://firebase.test/reset');
});

it('un usuario común no puede usar el recovery', function () {
    $juan = cliente();

    $this->actingAs(cliente('usuario'))->postJson("/api/admin/clients/{$juan->id}/recovery", ['mode' => 'reset_2fa'])->assertForbidden();
});

it('la compensación suma horas a la suscripción activa', function () {
    $c = cliente();
    $sub = conPlanActivo($c);
    $antes = $sub->fecha_vencimiento->copy();

    $this->actingAs(cliente('admin'))->postJson("/api/admin/clients/{$c->id}/compensate", ['horas' => 24])->assertOk();

    expect($sub->fresh()->fecha_vencimiento->diffInHours($antes, true))->toBe(24.0);
});

it('la contabilidad cruza pagos con suscripción y plan, y el reembolso respeta el monto', function () {
    $c = cliente();
    $sub = conPlanActivo($c, 'oro', 15.0);
    $pago = Pago::create(['suscripcion_id' => $sub->id, 'monto' => 15, 'pasarela_pago' => 'mercadopago', 'medio_pago' => 'tarjeta', 'transaccion_id' => 'T1', 'estado' => 'completado', 'fecha_transaccion' => now()]);
    $admin = $this->actingAs(cliente('admin'));

    $r = $admin->getJson('/api/admin/accounting')->assertOk();
    expect($r->json('kpis.ingresos_totales'))->toEqual(15.0)->and($r->json('kpis.mrr'))->toEqual(15.0)
        ->and($r->json('pagos.0.plan'))->toBe('oro')->and($r->json('pagos.0.cliente.email'))->toBe($c->email);

    $admin->postJson('/api/admin/accounting/refund', ['pago_id' => $pago->id, 'motivo' => 'Prueba', 'monto' => 99])->assertStatus(422);
    $admin->postJson('/api/admin/accounting/refund', ['pago_id' => $pago->id, 'motivo' => 'Cliente insatisfecho'])->assertCreated();

    expect($pago->fresh()->estado)->toBe('reembolsado');
    expect($admin->getJson('/api/admin/accounting')->json('kpis.total_reembolsado'))->toEqual(15.0);
});

it('registra un pago manual colgado de la suscripción (3FN)', function () {
    $sub = conPlanActivo(cliente());

    $this->actingAs(cliente('admin'))
        ->postJson('/api/admin/accounting/payment', ['suscripcion_id' => $sub->id, 'monto' => 10, 'medio_pago' => 'transferencia'])
        ->assertCreated();

    expect(Pago::where('suscripcion_id', $sub->id)->first())->pasarela_pago->toBe('manual')->estado->toBe('completado');
});

it('genera la factura PDF del pago', function () {
    $sub = conPlanActivo(cliente());
    $pago = Pago::create(['suscripcion_id' => $sub->id, 'monto' => 6, 'pasarela_pago' => 'stripe', 'medio_pago' => 'tarjeta', 'estado' => 'completado']);

    $res = $this->actingAs(cliente('admin'))->get("/api/admin/payments/{$pago->id}/pdf")->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($res->getContent(), 0, 4))->toBe('%PDF');
});

it('genera el PDF del ticket con el diagnóstico de Mine AI', function () {
    $c = cliente();
    $t = \App\Models\TicketSoporte::create(['cliente_id' => $c->id, 'estado' => 'abierto']);
    $t->mensajes()->create(['remitente' => 'user', 'mensaje' => 'No arranca mi servidor']);
    $t->mensajes()->create(['remitente' => 'ai', 'mensaje' => 'Mod duplicado', 'log_analizado' => '[ERROR] duplicate mod']);

    $res = $this->actingAs(cliente('soporte'))->get("/api/admin/tickets/{$t->id}/pdf")->assertOk();

    expect(substr($res->getContent(), 0, 4))->toBe('%PDF');
});

it('el soporte no accede a contabilidad y solo reinicia nodos', function () {
    $soporte = $this->actingAs(cliente('soporte'));

    $soporte->getJson('/api/admin/accounting')->assertForbidden();
    $soporte->postJson('/api/admin/server-action', ['serverId' => 'srv-x', 'action' => 'delete'])->assertForbidden();
});

it('congelar una cuenta marca el estado y avisa al demonio', function () {
    Http::fake(['*' => Http::response(['success' => true, 'afectados' => 1], 200)]);
    $c = cliente();

    $this->actingAs(cliente('admin'))->postJson("/api/admin/clients/{$c->id}/freeze", ['freeze' => true])->assertOk();

    expect($c->fresh()->estado)->toBe('congelado');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/admin/freeze') && $r['uid'] === $c->firebase_uid && $r['freeze'] === true);
});

it('con pagos el borrado es lógico aunque se pida físico', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $c = cliente();
    $sub = conPlanActivo($c);
    Pago::create(['suscripcion_id' => $sub->id, 'monto' => 6, 'pasarela_pago' => 'stripe', 'medio_pago' => 'tarjeta', 'estado' => 'completado']);

    $this->actingAs(cliente('ceo'))->deleteJson("/api/admin/clients/{$c->id}?hard=1")->assertOk()->assertJson(['tipo' => 'soft']);

    expect(Cliente::withTrashed()->find($c->id)->trashed())->toBeTrue();
});

it('el listado de planes y su edición respetan los dominios CHECK', function () {
    $plan = Plan::create(['nombre' => 'x', 'precio_mensual' => 5, 'memoria_mb' => 1024, 'cpu_cores' => 1, 'almacenamiento_gb' => 10]);
    $admin = $this->actingAs(cliente('admin'));

    $admin->patchJson("/api/admin/plans/{$plan->id}", ['memoria_mb' => 0])->assertStatus(422);
    $admin->patchJson("/api/admin/plans/{$plan->id}", ['precio_mensual' => 7.5, 'visible' => false])->assertOk();

    expect((float) $plan->fresh()->precio_mensual)->toBe(7.5);
    $this->getJson('/api/plans')->assertOk()->assertJsonMissing(['nombre' => 'x']);
});
