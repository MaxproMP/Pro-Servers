<?php

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('reenvía el token de Firebase al demonio Node', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $cliente = new Cliente(['firebase_uid' => 'uid-test']);

    $this->withoutMiddleware(\App\Http\Middleware\FirebaseTokenMiddleware::class)
        ->actingAs($cliente)
        ->postJson('/api/project/create', ['edition' => 'java'], ['Authorization' => 'Bearer token-abc'])
        ->assertOk();

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer token-abc')
        && $r['uid'] === 'uid-test');
});

it('al crear un servidor envía los límites del plan en SQL y descarta los del navegador', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $plan = App\Models\Plan::where('nombre', 'oro')->firstOrFail(); // sembrado por la migración
    $c = Cliente::create(['firebase_uid' => 'uid-plan', 'email' => 'plan@test.com', 'username' => 'plan', 'nombre' => 'P', 'password' => 'x', 'role' => 'usuario', 'plan_activo' => 'oro']);
    App\Models\Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => $plan->id, 'estado' => 'activa', 'ciclo_meses' => 1]);

    $this->withoutMiddleware(App\Http\Middleware\FirebaseTokenMiddleware::class)->actingAs($c)
        ->postJson('/api/project/create', ['edition' => 'java', 'plan' => ['memoria_mb' => 999999, 'cpu_cores' => 64]])
        ->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/project/create')
        && $r['plan']['memoria_mb'] === 32768 && $r['plan']['cpu_cores'] === 4 && $r['plan']['almacenamiento_gb'] === 80
        && $r['plan']['max_jugadores'] === 50);
});

it('sin plan activo no envía límites y Node usa los valores por defecto', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $c = Cliente::create(['firebase_uid' => 'uid-sp', 'email' => 'sp@test.com', 'username' => 'sp', 'nombre' => 'S', 'password' => 'x', 'role' => 'usuario', 'plan_activo' => 'ninguno']);

    $this->withoutMiddleware(App\Http\Middleware\FirebaseTokenMiddleware::class)->actingAs($c)
        ->postJson('/api/project/create', ['edition' => 'java', 'plan' => ['memoria_mb' => 999999]])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/project/create') && !isset($r['plan']));
});
