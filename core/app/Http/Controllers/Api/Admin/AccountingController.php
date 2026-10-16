<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AuditoriaReembolso;
use App\Models\Pago;
use App\Models\Suscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AccountingController extends AdminController
{
    public function summary(): JsonResponse
    {
        $completados = Pago::where('estado', 'completado');
        $activas = Suscripcion::pagas()->whereHas('cliente')->with('plan')->get();
        $procesados = AuditoriaReembolso::where('estado_auditoria', 'procesado');

        $mes = Pago::where('estado', 'completado')
            ->whereRaw('COALESCE(fecha_transaccion, created_at) >= ?', [now()->startOfMonth()])->sum('monto');

        $libro = Pago::with(['suscripcion.cliente', 'suscripcion.plan'])->orderByDesc('id')->limit(500)->get()
            ->map(fn (Pago $p) => [
                'id' => $p->id,
                'fecha' => ($p->fecha_transaccion ?? $p->created_at)?->toIso8601String(),
                'cliente' => $p->suscripcion?->cliente ? ['id' => $p->suscripcion->cliente->id, 'username' => $p->suscripcion->cliente->username, 'email' => $p->suscripcion->cliente->email] : null,
                'plan' => $p->suscripcion?->plan?->nombre,
                'suscripcion_id' => $p->suscripcion_id,
                'pasarela_pago' => $p->pasarela_pago,
                'medio_pago' => $p->medio_pago,
                'estado' => $p->estado,
                'monto' => (float) $p->monto,
                'transaccion_id' => $p->transaccion_id,
            ]);

        $reembolsos = AuditoriaReembolso::orderByDesc('id')->limit(200)->get()
            ->map(fn (AuditoriaReembolso $r) => ['id' => $r->id, 'pago_id' => $r->pago_id, 'cliente_id' => $r->cliente_id, 'motivo' => $r->motivo_solicitud, 'monto' => (float) $r->monto_devuelto, 'estado' => $r->estado_auditoria, 'fecha' => $r->created_at?->toIso8601String()]);

        return response()->json([
            'success' => true,
            'kpis' => [
                'ingresos_totales' => round((float) $completados->sum('monto'), 2),
                'mrr' => round($activas->sum(fn (Suscripcion $s) => (float) ($s->plan->precio_mensual ?? 0)), 2),
                'facturado_mes' => round((float) $mes, 2),
                'total_reembolsado' => round((float) $procesados->sum('monto_devuelto'), 2),
                'ticket_promedio' => round((float) (Pago::where('estado', 'completado')->avg('monto') ?? 0), 2),
                'suscripciones_activas' => $activas->count(),
            ],
            'pagos' => $libro,
            'reembolsos' => $reembolsos,
        ]);
    }

    /** Pago manual / compensación económica. Siempre cuelga de una suscripción (3FN). */
    public function payment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'suscripcion_id' => 'required|integer|exists:suscripciones,id',
            'monto' => 'required|numeric|gt:0|max:99999999',
            'medio_pago' => ['required', Rule::in(['transferencia', 'tarjeta', 'saldo', 'efectivo'])],
            'nota' => 'nullable|string|max:200',
        ]);

        $pago = Pago::create([
            'suscripcion_id' => $data['suscripcion_id'],
            'monto' => $data['monto'],
            'pasarela_pago' => 'manual',
            'medio_pago' => $data['medio_pago'],
            'transaccion_id' => 'MAN-' . Str::upper(Str::random(12)),
            'estado' => 'completado',
            'fecha_transaccion' => now(),
        ]);
        // El pago manual activa (o renueva) la suscripción igual que un webhook verificado.
        $activador = app(\App\Services\ActivadorPagos::class);
        $sub = $pago->suscripcion()->with(['cliente', 'plan'])->first();
        $activador->activarSuscripcion($sub);
        $activador->aplicarLimites($sub->fresh(['cliente', 'plan']));
        $this->auditar('admin.payment_manual', null, ['pago_id' => $pago->id, 'monto' => $data['monto'], 'nota' => $data['nota'] ?? null]);

        return response()->json(['success' => true, 'pago_id' => $pago->id], 201);
    }

    /** Confirma un pago pendiente (asistido o de pasarela) tras verificar el cobro en la consola del medio. */
    public function confirm(Request $request, \App\Services\ActivadorPagos $activador): JsonResponse
    {
        $data = $request->validate([
            'pago_id' => 'required|integer|exists:pagos,id',
            'medio_pago' => ['required', Rule::in(['transferencia', 'tarjeta', 'saldo', 'efectivo'])],
            'nota' => 'nullable|string|max:200',
        ]);
        $pago = Pago::findOrFail($data['pago_id']);
        if ($pago->estado !== 'pendiente') {
            return response()->json(['error' => 'Solo se confirman pagos pendientes.'], 422);
        }
        $activador->completar($pago, $data['medio_pago']);
        $this->auditar('admin.payment_confirmed', null, ['pago_id' => $pago->id, 'transaccion_id' => $pago->transaccion_id, 'nota' => $data['nota'] ?? null]);

        return response()->json(['success' => true, 'pago_id' => $pago->id]);
    }

    public function refund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pago_id' => 'required|integer|exists:pagos,id',
            'motivo' => 'required|string|min:5|max:500',
            'monto' => 'nullable|numeric|gt:0',
        ]);

        $pago = Pago::with('suscripcion')->findOrFail($data['pago_id']);
        if ($pago->estado !== 'completado') {
            return response()->json(['error' => 'Solo se pueden reembolsar pagos completados.'], 422);
        }
        $monto = (float) ($data['monto'] ?? $pago->monto);
        if ($monto > (float) $pago->monto) {
            return response()->json(['error' => 'El reembolso no puede superar el pago original.'], 422);
        }

        $aud = DB::transaction(function () use ($pago, $data, $monto): AuditoriaReembolso {
            $pago->update(['estado' => 'reembolsado']);

            return AuditoriaReembolso::create([
                'pago_id' => $pago->id,
                'cliente_id' => $pago->suscripcion->cliente_id,
                'motivo_solicitud' => $data['motivo'],
                'monto_devuelto' => $monto,
                'estado_auditoria' => 'procesado',
            ]);
        });
        $this->auditar('admin.refund', null, ['pago_id' => $pago->id, 'monto' => $monto]);

        return response()->json([
            'success' => true,
            'auditoria_id' => $aud->id,
            'aviso' => 'Reembolso registrado en SQL Server. La devolución en la pasarela debe hacerse en su consola.',
        ], 201);
    }

    public function pdf(int $id): Response
    {
        $pago = Pago::with(['suscripcion.cliente', 'suscripcion.plan'])->findOrFail($id);

        return TicketAdminController::renderPdf(view('pdf.invoice', ['pago' => $pago])->render(), "factura-{$pago->id}.pdf");
    }
}
