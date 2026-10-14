<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AuditEvent;
use App\Models\AuditoriaReembolso;
use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Models\TicketSoporte;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

final class OverviewController extends AdminController
{
    /** Métricas reales de SQL Server (3FN) y MongoDB. El soporte no recibe cifras financieras. */
    public function stats(): JsonResponse
    {
        $activas = Suscripcion::where('estado', 'activa')->whereHas('cliente');
        $total = Cliente::count();
        $conPlan = (clone $activas)->distinct()->count('cliente_id');

        $data = [
            'clientes' => [
                'total' => $total,
                'con_plan' => $conPlan,
                'sin_plan' => max(0, $total - $conPlan),
                'zona_hielo' => Cliente::where('estado', 'congelado')->count(),
                'eliminados' => Cliente::onlyTrashed()->count(),
            ],
            'suscripciones_activas' => (clone $activas)->count(),
            'conversion_pct' => $total > 0 ? round($conPlan / $total * 100, 1) : 0.0,
            'tickets' => [
                'abiertos' => TicketSoporte::where('estado', 'abierto')->count(),
                'cerrados' => TicketSoporte::where('estado', 'cerrado')->count(),
            ],
            'auditoria_24h' => $this->auditoria24h(),
        ];

        if (in_array($this->actor()->role, ['ceo', 'admin'], true)) {
            $data['financiero'] = $this->financiero($activas);
        }

        return response()->json(['success' => true] + $data);
    }

    private function financiero($activas): array
    {
        $pagos = Pago::where('estado', 'completado')
            ->get(['monto', 'fecha_transaccion', 'created_at'])
            ->map(fn (Pago $p) => ['monto' => (float) $p->monto, 'fecha' => Carbon::parse($p->fecha_transaccion ?? $p->created_at)]);

        $serie6m = collect(range(5, 0))->map(function (int $i) use ($pagos) {
            $mes = now()->startOfMonth()->subMonths($i);

            return [
                'mes' => $mes->format('Y-m'),
                'monto' => round($pagos->filter(fn ($p) => $p['fecha']->format('Y-m') === $mes->format('Y-m'))->sum('monto'), 2),
            ];
        })->values();

        $serie30d = collect(range(29, 0))->map(function (int $i) use ($pagos) {
            $dia = now()->startOfDay()->subDays($i);

            return [
                'dia' => $dia->format('Y-m-d'),
                'monto' => round($pagos->filter(fn ($p) => $p['fecha']->isSameDay($dia))->sum('monto'), 2),
            ];
        })->values();

        $subs = (clone $activas)->with('plan')->get();
        $reembolsos = AuditoriaReembolso::where('estado_auditoria', 'procesado');

        return [
            'ingresos_totales' => round($pagos->sum('monto'), 2),
            'ticket_promedio' => $pagos->count() ? round($pagos->avg('monto'), 2) : 0.0,
            'mrr' => round($subs->sum(fn (Suscripcion $s) => (float) ($s->plan->precio_mensual ?? 0)), 2),
            'facturado_mes' => round($pagos->filter(fn ($p) => $p['fecha']->isSameMonth(now()))->sum('monto'), 2),
            'serie_6m' => $serie6m,
            'serie_30d' => $serie30d,
            'planes' => $subs->groupBy(fn (Suscripcion $s) => $s->plan->nombre ?? 'sin plan')
                ->map(fn (Collection $g, string $plan) => ['plan' => $plan, 'clientes' => $g->count()])->values(),
            'reembolsos' => ['cantidad' => (clone $reembolsos)->count(), 'monto' => round((float) $reembolsos->sum('monto_devuelto'), 2)],
        ];
    }

    private function auditoria24h(): ?int
    {
        try {
            return AuditEvent::where('created_at', '>=', now()->subDay())->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Hardware del host + flota Docker con consumo en vivo, enriquecida con el dueño (SQL Server). */
    public function telemetry(): JsonResponse
    {
        $t = $this->node->get('/api/admin/telemetry');
        $f = $this->node->get('/api/admin/all-servers');

        if (!$t['ok']) {
            return response()->json(['success' => false, 'error' => $t['data']['error'] ?? 'Telemetría no disponible.'], 502);
        }

        $metricas = collect($t['data']['containers'] ?? [])->keyBy('serverId');
        $servers = collect($f['data']['servers'] ?? []);
        $duenos = Cliente::withTrashed()->whereIn('firebase_uid', $servers->pluck('uid')->unique())
            ->with('suscripcionActiva.plan')->get()->keyBy('firebase_uid');

        $flota = $servers->map(function (array $s) use ($metricas, $duenos) {
            $m = $metricas->get($s['id'], []);
            $d = $duenos->get($s['uid']);

            return $s + [
                'cpuPct' => $m['cpuPct'] ?? 0,
                'ramMb' => $m['ramMb'] ?? 0,
                'ramLimitMb' => $m['ramLimitMb'] ?? 0,
                'owner' => $d ? ['id' => $d->id, 'username' => $d->username, 'email' => $d->email, 'estado' => $d->estado, 'plan' => $d->suscripcionActiva?->plan?->nombre] : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'dockerUp' => $t['data']['dockerUp'] ?? false,
            'host' => $t['data']['host'] ?? [],
            'servers' => $flota,
        ]);
    }

    public function audit(): JsonResponse
    {
        try {
            $eventos = AuditEvent::orderBy('created_at', 'desc')->limit(80)->get()
                ->map(fn (AuditEvent $e) => [
                    'event' => $e->event,
                    'actor_type' => $e->actor_type,
                    'actor_id' => $e->actor_id,
                    'server_id' => $e->server_id,
                    'payload' => $e->payload,
                    'created_at' => $e->created_at?->toIso8601String(),
                ]);

            return response()->json(['success' => true, 'events' => $eventos]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'MongoDB no disponible.'], 502);
        }
    }
}
