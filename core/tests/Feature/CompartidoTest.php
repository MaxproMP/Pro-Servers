<?php

use App\Models\Cliente;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditLogService::class)->shouldIgnoreMissing();
    config(['services.daemon.secret' => 'secreto-daemon']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);
});

it('invitar resuelve la cuenta en SQL Server y reenvía a Node solo uid y username verificados', function () {
    // El doble de Firebase responde por el último token emitido: primero el invitado, después el dueño.
    $this->postJson('/api/login', [], tokenFirebase('uid-amigo', 'amigo@gmail.com'))->assertOk();
    $amigo = Cliente::where('firebase_uid', 'uid-amigo')->first();
    $dueno = tokenFirebase('uid-dueno', 'dueno@gmail.com');
    $this->postJson('/api/login', [], $dueno)->assertOk();

    $this->postJson('/api/server/members/add', ['serverId' => 'srv-x', 'usuario' => 'AMIGO@gmail.com'], $dueno)->assertCreated();
    $this->postJson('/api/server/members/add', ['serverId' => 'srv-x', 'usuario' => '@'.$amigo->username], $dueno)->assertCreated();

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/server/members/add')
        && $r->hasHeader('x-daemon-secret', 'secreto-daemon')
        && $r['invitado'] === ['uid' => 'uid-amigo', 'username' => $amigo->username]);
});

it('invitar a una cuenta inexistente, congelada o a uno mismo no llega a Node', function () {
    $this->postJson('/api/login', [], tokenFirebase('uid-hielo', 'hielo@gmail.com'))->assertOk();
    Cliente::where('firebase_uid', 'uid-hielo')->update(['estado' => 'congelado']);
    $dueno = tokenFirebase('uid-dueno', 'dueno@gmail.com');
    $this->postJson('/api/login', [], $dueno)->assertOk();

    $this->postJson('/api/server/members/add', ['serverId' => 'srv-x', 'usuario' => 'nadie@gmail.com'], $dueno)->assertNotFound();
    $this->postJson('/api/server/members/add', ['serverId' => 'srv-x', 'usuario' => 'hielo@gmail.com'], $dueno)->assertNotFound();
    $this->postJson('/api/server/members/add', ['serverId' => 'srv-x', 'usuario' => 'dueno@gmail.com'], $dueno)->assertStatus(422);
    $this->postJson('/api/server/members/add', ['serverId' => '../x', 'usuario' => 'a'], $dueno)->assertStatus(422);
    Http::assertNothingSent();
});

it('el navegador no puede inyectar un invitado por el proxy genérico', function () {
    $h = tokenFirebase('uid-a', 'a@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();

    $this->postJson('/api/server/members/remove', ['serverId' => 'srv-x', 'memberUid' => 'uid-b', 'invitado' => ['uid' => 'uid-atacante'], 'duenoUsername' => 'falso'], $h)->assertCreated();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/server/members/remove') && ! isset($r['invitado']) && ! isset($r['duenoUsername']) && $r['memberUid'] === 'uid-b');
});
