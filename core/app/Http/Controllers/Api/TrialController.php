<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Prueba gratis: una por cuenta (nunca tuvo suscripción pagada ni prueba), sin cobro ni pago asociado. */
final class TrialController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'elegible' => $this->elegible($request->user()),
            'dias' => (int) config('services.trial.dias'),
            'planes' => config('services.trial.planes'),
        ]);
    }

    public function start(Request $request, AuditLogService $audit): JsonResponse
    {
        $data = $request->validate([
            'plan_nombre' => ['required', 'string', Rule::in(config('services.trial.planes'))],
        ]);

        // Anti-abuso: la prueba exige email verificado por Firebase.
        if ($request->attributes->get('email_verified') !== true) {
            return response()->json(['success' => false, 'message' => 'Verificá tu email para activar la prueba gratis.'], 403);
        }

        $plan = Plan::where('nombre', $data['plan_nombre'])->where('visible', true)->first();
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Ese plan no está disponible.'], 422);
        }

        $dias = (int) config('services.trial.dias');

        // Bloqueo de la fila del cliente: dos pedidos simultáneos no pueden abrir dos pruebas.
        $sub = DB::transaction(function () use ($request, $plan, $dias): ?Suscripcion {
            $cliente = Cliente::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->elegible($cliente)) {
                return null;
            }
            $fin = now()->addDays($dias);

            return Suscripcion::create([
                'cliente_id' => $cliente->id,
                'plan_id' => $plan->id,
                'estado' => 'activa',
                'ciclo_meses' => 1,
                'fecha_inicio' => now(),
                'fecha_vencimiento' => $fin,
                'trial_ends_at' => $fin,
            ]);
        });

        if (! $sub) {
            return response()->json(['success' => false, 'message' => 'Tu cuenta ya usó la prueba gratis o tiene una suscripción.'], 409);
        }

        try {
            $audit->record('billing.trial.started', 'cliente', (string) $sub->cliente_id, null, ['plan' => $plan->nombre, 'hasta' => $sub->trial_ends_at->toIso8601String()]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'suscripcion' => ['id' => $sub->id, 'plan' => $plan->nombre, 'trial_ends_at' => $sub->trial_ends_at->toIso8601String()],
        ], 201);
    }

    private function elegible(Cliente $cliente): bool
    {
        return $cliente->estado === 'activo'
            && ! Suscripcion::withTrashed()->where('cliente_id', $cliente->id)
                // Un checkout abandonado (pendiente) no consume la prueba; cualquier otra suscripción sí.
                ->where(fn ($q) => $q->whereNotNull('trial_ends_at')->orWhere('estado', '!=', 'pendiente'))
                ->exists();
    }
}
