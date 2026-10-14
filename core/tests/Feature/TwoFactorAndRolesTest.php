<?php

use App\Http\Middleware\FirebaseTokenMiddleware;
use App\Models\Cliente;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

function clienteCon2fa(string $secret): Cliente
{
    $c = new Cliente(['email' => 't@proservers.com', 'role' => 'usuario']);
    $c->id = 7;
    $c->google2fa_secret = Crypt::encryptString($secret);

    return $c;
}

it('rechaza un código TOTP incorrecto', function () {
    $secret = (new Google2FA())->generateSecretKey();

    $this->withoutMiddleware(FirebaseTokenMiddleware::class)
        ->actingAs(clienteCon2fa($secret))
        ->postJson('/api/2fa/verify', ['code' => '000000'])
        ->assertStatus(422);

    expect(Cache::has('2fa:7:0'))->toBeFalse();
});

it('acepta un código TOTP válido y marca la sesión como verificada', function () {
    $g = new Google2FA();
    $secret = $g->generateSecretKey();

    $this->withoutMiddleware(FirebaseTokenMiddleware::class)
        ->actingAs(clienteCon2fa($secret))
        ->postJson('/api/2fa/verify', ['code' => $g->getCurrentOtp($secret)])
        ->assertOk();

    expect(Cache::has('2fa:7:0'))->toBeTrue();
});

it('solo el CEO asigna roles de staff', function (string $rol) {
    $this->withoutMiddleware(FirebaseTokenMiddleware::class)
        ->actingAs(new Cliente(['role' => $rol]))
        ->postJson('/api/admin/staff/add', ['emailToStaff' => 'x@test.com'])
        ->assertForbidden();
})->with(['usuario', 'soporte', 'admin']);

it('un usuario común no puede ver el staff', function () {
    $this->withoutMiddleware(FirebaseTokenMiddleware::class)
        ->actingAs(new Cliente(['role' => 'usuario']))
        ->getJson('/api/admin/staff/list')
        ->assertForbidden();
});

it('el soporte no accede a los tickets si es un usuario común', function () {
    $this->withoutMiddleware(FirebaseTokenMiddleware::class)
        ->actingAs(new Cliente(['role' => 'usuario']))
        ->getJson('/api/admin/support-tickets')
        ->assertForbidden();
});
