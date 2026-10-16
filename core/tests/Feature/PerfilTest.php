<?php

use App\Models\Cliente;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditLogService::class)->shouldIgnoreMissing();
});

it('una cuenta de Google edita sus datos personales y solo los suyos', function () {
    $this->postJson('/api/login', [], tokenFirebase('uid-otro', 'otro@gmail.com'))->assertOk();
    Cliente::where('firebase_uid', 'uid-otro')->update(['username' => 'ocupado']);
    $h = tokenFirebase('uid-g', 'g@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();

    $this->patchJson('/api/perfil', ['nombre' => 'María José', 'apellido' => "O'Neill", 'username' => '@Maxpro_10', 'phone' => '+54 9 11 1234-5678', 'firebase_uid' => 'uid-otro', 'role' => 'ceo'], $h)
        ->assertOk()->assertJsonPath('perfil.username', 'maxpro_10');

    $c = Cliente::where('firebase_uid', 'uid-g')->first();
    expect([$c->nombre, $c->apellido, $c->phone, $c->role])->toBe(['María José', "O'Neill", '+54 9 11 1234-5678', 'usuario']);
    expect(Cliente::where('firebase_uid', 'uid-otro')->value('username'))->toBe('ocupado');
});

it('valida usuario único, formato y campos obligatorios', function () {
    $this->postJson('/api/login', [], tokenFirebase('uid-otro', 'otro@gmail.com'))->assertOk();
    Cliente::where('firebase_uid', 'uid-otro')->update(['username' => 'ocupado']);
    $h = tokenFirebase('uid-g', 'g@gmail.com');
    $this->postJson('/api/login', [], $h)->assertOk();

    $this->patchJson('/api/perfil', ['nombre' => 'Ana', 'username' => 'ocupado'], $h)->assertStatus(422)->assertJsonValidationErrors('username');
    $this->patchJson('/api/perfil', ['nombre' => 'Ana', 'username' => 'a b<script>'], $h)->assertStatus(422)->assertJsonValidationErrors('username');
    $this->patchJson('/api/perfil', ['nombre' => '', 'username' => 'anita', 'phone' => 'llamame'], $h)->assertStatus(422)->assertJsonValidationErrors(['nombre', 'phone']);
});
