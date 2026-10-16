<?php

use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Laravel\Firebase\Facades\Firebase;

uses(RefreshDatabase::class);

function firebaseComo(string $uid, string $email): void
{
    $claims = ['sub' => $uid, 'email' => $email, 'name' => 'Cliente Prueba', 'email_verified' => true, 'auth_time' => 1];
    $token = new class($claims) {
        public function __construct(private array $c) {}
        public function claims(): object { $c = $this->c; return new class($c) { public function __construct(private array $c) {} public function get(string $k) { return $this->c[$k] ?? null; } }; }
    };
    $auth = new class($token) { public function __construct(private object $t) {} public function verifyIdToken(string $jwt): object { return $this->t; } };
    Firebase::swap(new class($auth) { public function __construct(private object $a) {} public function auth(): object { return $this->a; } });
}

beforeEach(function () {
    $this->mock(AuditLogService::class)->shouldIgnoreMissing();
    firebaseComo('uid-trial', 'trial@gmail.com');
    $this->postJson('/api/login', [], ['Authorization' => 'Bearer tok'])->assertOk();
    $this->h = ['Authorization' => 'Bearer tok'];
});

it('el catálogo público expone la escala v2 con jugadores máximos', function () {
    $planes = collect($this->getJson('/api/plans')->assertOk()->json('planes'))->keyBy('nombre');

    expect((float) $planes['redstone']['precio_mensual'])->toBe(6.0)
        ->and((int) $planes['redstone']['memoria_mb'])->toBe(8192)
        ->and((int) $planes['redstone']['max_jugadores'])->toBe(4)
        ->and((int) $planes['netherite']['max_jugadores'])->toBe(200)
        ->and($planes['ghost-warrior']['max_jugadores'])->toBeNull()
        ->and($planes['redstone']['prueba'])->toBeTrue()
        ->and($planes['netherite']['prueba'])->toBeFalse();
});

it('una cuenta nueva activa 15 días de prueba sin generar pagos', function () {
    $this->getJson('/api/trial/status', $this->h)->assertOk()->assertJson(['elegible' => true, 'dias' => 15]);

    $this->postJson('/api/trial/start', ['plan_nombre' => 'redstone'], $this->h)->assertCreated();

    $sub = Suscripcion::firstOrFail();
    expect($sub->estado)->toBe('activa')
        ->and($sub->enPrueba())->toBeTrue()
        ->and((int) round(now()->diffInDays($sub->trial_ends_at)))->toBe(15)
        ->and($sub->pagos()->count())->toBe(0);
    expect(Cliente::firstOrFail()->suscripcionActiva?->id)->toBe($sub->id);
});

it('la prueba es una sola por cuenta', function () {
    $this->postJson('/api/trial/start', ['plan_nombre' => 'hierro'], $this->h)->assertCreated();
    $this->postJson('/api/trial/start', ['plan_nombre' => 'redstone'], $this->h)->assertStatus(409);
    $this->getJson('/api/trial/status', $this->h)->assertJson(['elegible' => false]);
    expect(Suscripcion::count())->toBe(1);
});

it('solo los planes de entrada admiten prueba', function () {
    $this->postJson('/api/trial/start', ['plan_nombre' => 'netherite'], $this->h)->assertStatus(422);
    expect(Suscripcion::count())->toBe(0);
});

it('un checkout abandonado no consume la prueba, pero una suscripción pagada sí', function () {
    $c = Cliente::firstOrFail();
    $plan = Plan::where('nombre', 'oro')->firstOrFail();
    Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => $plan->id, 'estado' => 'pendiente', 'ciclo_meses' => 1]);
    $this->getJson('/api/trial/status', $this->h)->assertJson(['elegible' => true]);

    Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => $plan->id, 'estado' => 'activa', 'ciclo_meses' => 1]);
    $this->getJson('/api/trial/status', $this->h)->assertJson(['elegible' => false]);
});

it('una prueba vencida deja de contar como suscripción activa', function () {
    $this->postJson('/api/trial/start', ['plan_nombre' => 'cobre'], $this->h)->assertCreated();
    $this->travel(16)->days();

    expect(Cliente::firstOrFail()->suscripcionActiva)->toBeNull();
});

it('editar un plan desde Overwatch invalida el catálogo cacheado', function () {
    $this->getJson('/api/plans')->assertOk(); // calienta la caché
    $hs = tokenFirebase('uid-ceo', config('services.ceo_email'));
    $this->postJson('/api/login', [], $hs)->assertOk();
    $id = App\Models\Plan::where('nombre', 'hierro')->value('id');
    $this->patchJson("/api/admin/plans/{$id}", ['precio_mensual' => 10], $hs)->assertOk();

    $p = collect($this->getJson('/api/plans')->json('planes'))->firstWhere('nombre', 'hierro');
    expect((float) $p['precio_mensual'])->toBe(10.0);
});
