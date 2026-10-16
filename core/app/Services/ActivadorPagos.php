<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pago;
use App\Models\Suscripcion;
use Illuminate\Support\Facades\DB;

/**
 * Único camino para pasar un pago a "completado" y activar su suscripción.
 * Lo usan los webhooks verificados de las pasarelas y la confirmación manual desde el Panel Admin.
 */
final class ActivadorPagos
{
    public function __construct(private NodeClient $node) {}

    /** Idempotente: si el pago ya estaba completado no hace nada y devuelve false. */
    public function completar(Pago $pago, ?string $medio = null): bool
    {
        $activado = DB::transaction(function () use ($pago, $medio): bool {
            $p = Pago::whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            if ($p->estado === 'completado') {
                return false;
            }
            $p->update([
                'estado' => 'completado',
                'fecha_transaccion' => now(),
                'medio_pago' => $medio ?: $p->medio_pago,
            ]);
            $this->activarSuscripcion($p->suscripcion);

            return true;
        });

        if ($activado) {
            $this->aplicarLimites($pago->suscripcion()->with(['cliente', 'plan'])->first());
        }

        return $activado;
    }

    /** Activa (o renueva) la suscripción por su ciclo y da de baja pruebas que quedaran abiertas. */
    public function activarSuscripcion(Suscripcion $s): void
    {
        $meses = max(1, (int) $s->ciclo_meses);
        // Renovación: si todavía estaba vigente, el ciclo nuevo se suma al vencimiento actual.
        $base = $s->estado === 'activa' && $s->fecha_vencimiento?->isFuture() ? $s->fecha_vencimiento->copy() : now();

        $s->update([
            'estado' => 'activa',
            'fecha_inicio' => $s->fecha_inicio ?? now(),
            'fecha_vencimiento' => $base->addMonths($meses),
            'trial_ends_at' => null,
        ]);

        Suscripcion::where('cliente_id', $s->cliente_id)->whereKeyNot($s->getKey())
            ->whereNotNull('trial_ends_at')->where('estado', 'activa')
            ->update(['estado' => 'reemplazada']);

        $s->cliente?->update(['plan_activo' => $s->plan?->nombre ?? 'ninguno']);
    }

    /** Los contenedores existentes del cliente pasan a los límites del plan pagado. */
    public function aplicarLimites(?Suscripcion $s): void
    {
        if (! $s?->cliente?->firebase_uid || ! $s->plan) {
            return;
        }
        $r = $this->node->post('/api/admin/apply-limits', ['uid' => $s->cliente->firebase_uid, 'plan' => [
            'nombre' => $s->plan->nombre,
            'memoria_mb' => (int) $s->plan->memoria_mb,
            'cpu_cores' => (int) $s->plan->cpu_cores,
            'almacenamiento_gb' => (int) $s->plan->almacenamiento_gb,
            'max_jugadores' => $s->plan->max_jugadores === null ? null : (int) $s->plan->max_jugadores,
        ]]);
        if (! $r['ok']) {
            report(new \RuntimeException('No se pudieron aplicar límites tras el pago: '.json_encode($r['data'])));
        }
    }
}
