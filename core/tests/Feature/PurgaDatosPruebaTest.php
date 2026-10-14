<?php

use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Pago;
use App\Models\Server;
use App\Models\Suscripcion;
use App\Services\TestDataPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cuenta(string $email, string $rol = 'usuario'): Cliente
{
    static $n = 0;
    $n++;

    return Cliente::create(['firebase_uid' => "u$n", 'email' => $email, 'username' => "user$n", 'nombre' => 'N', 'password' => 'x', 'role' => $rol, 'plan_activo' => 'ninguno']);
}

function conServidor(Cliente $c): Suscripcion
{
    $s = Suscripcion::create(['cliente_id' => $c->id, 'plan_id' => Plan::first()->id, 'estado' => 'activa', 'ciclo_meses' => 1]);
    Server::create(['suscripcion_id' => $s->id, 'status' => 'active']);

    return $s;
}

it('elimina físicamente las cuentas de prueba y conserva las reales', function () {
    $falso = cuenta('bruno@example.com'); conServidor($falso);
    $admin = cuenta('admin@proservers.com', 'ceo');
    $ceo = cuenta(config('services.ceo_email'), 'ceo');
    $real = cuenta('cliente@gmail.com'); conServidor($real);

    $resumen = app(TestDataPurger::class)->purgarSql(true);

    expect($resumen['clientes'])->toHaveCount(2);
    expect(Cliente::withTrashed()->pluck('email')->all())->toEqualCanonicalizing([config('services.ceo_email'), 'cliente@gmail.com']);
    expect(Suscripcion::withTrashed()->count())->toBe(1)->and(Server::count())->toBe(1);
});

it('el simulacro no borra nada', function () {
    cuenta('x@example.org');

    $r = app(TestDataPurger::class)->purgarSql(false);

    expect($r['clientes'])->toHaveCount(1)->and(Cliente::count())->toBe(1);
});

it('jamás borra una cuenta de prueba con historial de pagos ni al CEO', function () {
    $conPagos = cuenta('pago@example.com');
    $sub = conServidor($conPagos);
    Pago::create(['suscripcion_id' => $sub->id, 'monto' => 6, 'pasarela_pago' => 'stripe', 'medio_pago' => 'tarjeta', 'estado' => 'completado']);
    cuenta(config('services.ceo_email'), 'ceo');

    app(TestDataPurger::class)->purgarSql(true);

    expect(Cliente::count())->toBe(2);
});

it('también limpia las cuentas de prueba que ya estaban con borrado lógico', function () {
    $c = cuenta('viejo@example.net'); $c->delete();

    app(TestDataPurger::class)->purgarSql(true);

    expect(Cliente::withTrashed()->count())->toBe(0);
});
