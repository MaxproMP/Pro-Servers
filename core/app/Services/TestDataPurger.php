<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cliente;
use App\Models\Server;
use App\Models\Suscripcion;
use App\Models\TicketSoporte;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina los datos de prueba (seeders, Faker, fixtures) sin tocar cuentas reales.
 * Una cuenta de prueba usa un dominio reservado (RFC 2606) o es el admin sembrado.
 * Nunca se elimina al CEO ni a ningún cliente con historial de pagos.
 */
class TestDataPurger
{
    private const FIXTURES_ACTOR = ['validation', 'refund-smoke-test'];

    /** @return Collection<int, Cliente> */
    public function clientesDePrueba(): Collection
    {
        return Cliente::withTrashed()
            ->where(fn ($q) => $q->where('email', 'like', '%@example.com')
                ->orWhere('email', 'like', '%@example.net')
                ->orWhere('email', 'like', '%@example.org')
                ->orWhere('email', 'admin@proservers.com'))
            ->where('email', '!=', (string) config('services.ceo_email'))
            ->get()
            ->reject(fn (Cliente $c) => $this->tienePagos($c));
    }

    /** Borrado físico en SQL Server de las cuentas de prueba y de todas sus relaciones. */
    public function purgarSql(bool $aplicar): array
    {
        $clientes = $this->clientesDePrueba();
        $resumen = ['clientes' => $clientes->map(fn (Cliente $c) => ['id' => $c->id, 'email' => $c->email, 'username' => $c->username])->values()->all(), 'suscripciones' => 0, 'servidores' => 0, 'tickets' => 0];

        $subIds = Suscripcion::withTrashed()->whereIn('cliente_id', $clientes->pluck('id'))->pluck('id');
        $resumen['suscripciones'] = $subIds->count();
        $resumen['servidores'] = Server::whereIn('suscripcion_id', $subIds)->count();
        $resumen['tickets'] = TicketSoporte::whereIn('cliente_id', $clientes->pluck('id'))->count();

        if ($aplicar && $clientes->isNotEmpty()) {
            DB::transaction(function () use ($clientes, $subIds): void {
                TicketSoporte::whereIn('cliente_id', $clientes->pluck('id'))->delete();
                Server::whereIn('suscripcion_id', $subIds)->delete();
                Suscripcion::withTrashed()->whereIn('id', $subIds)->forceDelete();
                if (Schema::hasTable('webauthn_credentials')) {
                    DB::table('webauthn_credentials')->where('authenticatable_type', Cliente::class)->whereIn('authenticatable_id', $clientes->pluck('id'))->delete();
                }
                Cliente::withTrashed()->whereIn('id', $clientes->pluck('id'))->forceDelete();
            });
        }

        return $resumen;
    }

    /**
     * Eventos huérfanos de MongoDB: fixtures de pruebas, eventos de clientes inexistentes o de servidores
     * que ya no existen en Docker. $servidores son los ids de servidores vigentes.
     *
     * @param  array<int, string>  $servidores
     */
    public function purgarMongo(bool $aplicar, array $servidores, array $actoresExtra = []): array
    {
        $validos = [...Cliente::withTrashed()->pluck('firebase_uid')->filter()->all(), ...Cliente::withTrashed()->pluck('id')->map(fn ($i) => (string) $i)->all()];
        $candidatos = [];

        foreach (AuditEvent::all() as $e) {
            $motivo = null;
            if (in_array($e->actor_id, [...self::FIXTURES_ACTOR, ...$actoresExtra], true)) {
                $motivo = 'evento de prueba o de una cuenta de prueba eliminada';
            } elseif (in_array($e->actor_type, ['user', 'client'], true) && !in_array($e->actor_id, $validos, true)) {
                $motivo = 'cliente inexistente en SQL Server';
            } elseif ($e->server_id && !in_array($e->server_id, $servidores, true)) {
                $motivo = 'servidor inexistente en Docker';
            }
            if ($motivo) {
                $candidatos[] = ['id' => (string) $e->getKey(), 'event' => $e->event, 'motivo' => $motivo];
            }
        }

        if ($aplicar && $candidatos) {
            AuditEvent::whereIn('_id', array_column($candidatos, 'id'))->delete();
        }

        return $candidatos;
    }

    private function tienePagos(Cliente $c): bool
    {
        return DB::table('pagos')->whereIn('suscripcion_id', Suscripcion::withTrashed()->where('cliente_id', $c->id)->pluck('id'))->exists();
    }
}
