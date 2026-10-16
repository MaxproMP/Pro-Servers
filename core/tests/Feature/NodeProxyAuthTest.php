<?php

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(App\Services\AuditLogService::class)->shouldIgnoreMissing();
    config(['services.daemon.secret' => 'secreto-daemon']);
});

it('reenvía el token de Firebase al demonio Node', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $h = tokenFirebase('uid-test', 'test@gmail.com');

    $this->postJson('/api/project/create', ['edition' => 'java'], $h)->assertOk();

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok-uid-test'));
});

it('al crear un servidor envía los límites del plan en SQL y descarta los del navegador', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $h = tokenFirebase('uid-plan', 'plan@test.com');
    $this->postJson('/api/login', [], $h)->assertOk();
    conPlan('uid-plan', 'oro');

    $this->postJson('/api/project/create', ['edition' => 'java', 'plan' => ['memoria_mb' => 999999, 'cpu_cores' => 64]], $h)
        ->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/project/create')
        && $r['plan']['memoria_mb'] === 32768 && $r['plan']['cpu_cores'] === 4 && $r['plan']['almacenamiento_gb'] === 80
        && $r['plan']['max_jugadores'] === 50);
});

it('sin plan activo no envía límites y Node usa los valores por defecto', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    $h = tokenFirebase('uid-sp', 'sp@test.com');

    $this->postJson('/api/project/create', ['edition' => 'java', 'plan' => ['memoria_mb' => 999999]], $h)->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/project/create') && !isset($r['plan']));
});
