<?php

use App\Models\Cliente;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Laravel\Firebase\Facades\Firebase;

uses(RefreshDatabase::class);

/** Simula la verificación del ID token de Firebase con los claims indicados. */
function firebaseCon(array $claims): void
{
    $token = new class($claims) {
        public function __construct(private array $c) {}
        public function claims(): object { $c = $this->c; return new class($c) { public function __construct(private array $c) {} public function get(string $k) { return $this->c[$k] ?? null; } }; }
    };
    $auth = new class($token) { public function __construct(private object $t) {} public function verifyIdToken(string $jwt): object { return $this->t; } };
    Firebase::swap(new class($auth) { public function __construct(private object $a) {} public function auth(): object { return $this->a; } });
}

it('el registro por correo guarda un cliente limpio y real en SQL Server', function () {
    firebaseCon(['sub' => 'uid-real-1', 'email' => 'ana@gmail.com', 'name' => 'Ana Pérez | anap', 'email_verified' => true, 'auth_time' => 1]);

    $this->postJson('/api/register', ['username' => 'anap', 'nombre' => 'Ana', 'apellido' => 'Pérez', 'phone' => '+5491112345678'], ['Authorization' => 'Bearer tok'])
        ->assertCreated();

    $c = Cliente::where('firebase_uid', 'uid-real-1')->firstOrFail();
    expect($c->email)->toBe('ana@gmail.com')->and($c->username)->toBe('anap')->and($c->nombre)->toBe('Ana')
        ->and($c->apellido)->toBe('Pérez')->and($c->phone)->toBe('+5491112345678')
        ->and($c->role)->toBe('usuario')->and($c->estado)->toBe('activo')->and($c->plan_activo)->toBe('ninguno')
        ->and($c->google2fa_secret)->toBeNull();
    expect(Cliente::count())->toBe(1);
});

it('el login social crea la cuenta desde los datos de Google y la deja sin 2FA', function () {
    firebaseCon(['sub' => 'uid-google-1', 'email' => 'luis@gmail.com', 'name' => 'Luis Gómez', 'email_verified' => true, 'auth_time' => 1]);

    $this->postJson('/api/login', ['firebase_uid' => 'uid-google-1'], ['Authorization' => 'Bearer tok'])
        ->assertOk()->assertJson(['requires_2fa' => false]);

    $c = Cliente::where('firebase_uid', 'uid-google-1')->firstOrFail();
    expect($c->email)->toBe('luis@gmail.com')->and($c->nombre)->toBe('Luis')->and($c->apellido)->toBe('Gómez')->and($c->username)->toBe('luis');
});

it('dos cuentas con el mismo inicio de correo reciben usernames distintos', function () {
    firebaseCon(['sub' => 'uid-a', 'email' => 'juan@gmail.com', 'name' => 'Juan A', 'email_verified' => true, 'auth_time' => 1]);
    $this->postJson('/api/login', [], ['Authorization' => 'Bearer tok'])->assertOk();
    firebaseCon(['sub' => 'uid-b', 'email' => 'juan@outlook.com', 'name' => 'Juan B', 'email_verified' => true, 'auth_time' => 1]);
    $this->postJson('/api/login', [], ['Authorization' => 'Bearer tok'])->assertOk();

    expect(Cliente::pluck('username')->unique()->count())->toBe(2);
});

it('la cuenta nueva aparece al instante en el directorio del panel con datos reales', function () {
    $this->mock(AuditLogService::class);
    firebaseCon(['sub' => 'uid-ceo', 'email' => config('services.ceo_email'), 'name' => 'Maxpro', 'email_verified' => true, 'auth_time' => 1]);
    $this->getJson('/api/user/status', ['Authorization' => 'Bearer tok'])->assertJson(['role' => 'ceo']);

    // Directorio con solo el CEO: la realidad exacta, sin filas inventadas.
    $this->getJson('/api/admin/clients', ['Authorization' => 'Bearer tok'])->assertOk()->assertJsonCount(1, 'clients');
    $this->getJson('/api/admin/stats', ['Authorization' => 'Bearer tok'])->assertOk()
        ->assertJsonPath('clientes.total', 1)->assertJsonPath('clientes.con_plan', 0)->assertJsonPath('suscripciones_activas', 0)
        ->assertJsonPath('financiero.planes', [])->assertJsonPath('financiero.ingresos_totales', 0);

    // Se registra un cliente nuevo.
    firebaseCon(['sub' => 'uid-nuevo', 'email' => 'nuevo@gmail.com', 'name' => 'Nora Díaz', 'email_verified' => true, 'auth_time' => 1]);
    $this->postJson('/api/register', ['username' => 'nora', 'nombre' => 'Nora', 'apellido' => 'Díaz', 'phone' => '1155550000'], ['Authorization' => 'Bearer tok'])->assertCreated();

    // El CEO lo ve inmediatamente.
    firebaseCon(['sub' => 'uid-ceo', 'email' => config('services.ceo_email'), 'name' => 'Maxpro', 'email_verified' => true, 'auth_time' => 1]);
    $lista = $this->getJson('/api/admin/clients', ['Authorization' => 'Bearer tok'])->assertOk()->json('clients');
    $nora = collect($lista)->firstWhere('email', 'nuevo@gmail.com');
    expect($lista)->toHaveCount(2)->and($nora['username'])->toBe('nora')->and($nora['plan'])->toBeNull()->and($nora['server'])->toBeNull()
        ->and($nora['rol'])->toBe('usuario')->and($nora['estado'])->toBe('activo')->and($nora['has_2fa'])->toBeFalse();
    $this->getJson('/api/admin/stats', ['Authorization' => 'Bearer tok'])->assertJsonPath('clientes.total', 2)->assertJsonPath('clientes.sin_plan', 2);
});
