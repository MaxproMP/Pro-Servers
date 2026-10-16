<?php

use App\Models\Cliente;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditLogService::class)->shouldIgnoreMissing();
});

it('el chat muestra el nombre de pila del operador que respondió y la presencia real del staff', function () {
    $this->postJson('/api/login', [], tokenFirebase('uid-cli', 'cli@gmail.com'))->assertOk();
    $this->postJson('/api/support/chat', ['message' => 'No arranca mi servidor'], tokenFirebase('uid-cli', 'cli@gmail.com'))->assertOk();

    $this->getJson('/api/support/messages?todos=1', tokenFirebase('uid-cli', 'cli@gmail.com'))->assertOk()
        ->assertJsonPath('equipo.operador', null)->assertJsonPath('equipo.en_linea', false)
        ->assertJsonPath('messages.0.remitente', 'user');

    $staff = tokenFirebase('uid-staff', 'staff@gmail.com');
    $this->postJson('/api/login', [], $staff)->assertOk();
    Cliente::where('firebase_uid', 'uid-staff')->update(['role' => 'soporte', 'nombre' => 'Lautaro Gómez']);
    $this->postJson('/api/admin/support-reply', ['uid' => 'uid-cli', 'reply' => 'Ya lo reviso'], $staff)->assertOk();

    $r = $this->getJson('/api/support/messages?todos=1', tokenFirebase('uid-cli', 'cli@gmail.com'))->assertOk()
        ->assertJsonPath('equipo.operador', 'Lautaro')->assertJsonPath('equipo.en_linea', true)
        ->assertJsonPath('messages.1.autor', 'Lautaro')->assertJsonPath('messages.1.remitente', 'admin');
    expect(json_encode($r->json()))->not->toContain('staff@gmail.com')->not->toContain('Gómez');

    // Sin ?todos: solo respuestas del staff (compatibilidad con el chat de crear.html).
    $this->getJson('/api/support/messages', tokenFirebase('uid-cli', 'cli@gmail.com'))->assertJsonCount(1, 'messages');
});

it('el staff deja de figurar en línea tras 5 minutos sin actividad', function () {
    $this->postJson('/api/login', [], tokenFirebase('uid-staff', 'staff@gmail.com'))->assertOk();
    Cliente::where('firebase_uid', 'uid-staff')->update(['role' => 'soporte', 'ultima_actividad' => now()->subMinutes(6)]);
    $this->postJson('/api/login', [], tokenFirebase('uid-cli', 'cli@gmail.com'))->assertOk();

    $this->getJson('/api/support/messages', tokenFirebase('uid-cli', 'cli@gmail.com'))
        ->assertJsonPath('equipo.en_linea', false)->assertJsonPath('equipo.ultima_conexion', fn ($v) => $v !== null);
});
