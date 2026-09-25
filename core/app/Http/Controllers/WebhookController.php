<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Suscripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    // 1. WEBHOOK DE ASTROPAY
    public function webhookAstropay(Request $request)
    {
        $estado = $request->input('status'); // Ej: 'APPROVED'
        $transaccionId = $request->input('merchant_deposit_id') ?? $request->input('merchant_order_id');
        $firebaseUid = $request->input('user_id');
        $planNombre = $request->input('plan_name');
        $monto = $request->input('amount');

        if ($estado === 'APPROVED' || $estado === 'approved') {
            return $this->procesarPagoExitoso($firebaseUid, $planNombre, $monto, 'AstroPay', $transaccionId);
        }

        return response()->json(['mensaje' => 'Pago no aprobado aún'], 200);
    }

    // 2. WEBHOOK DE LEMONCASH
    public function webhookLemoncash(Request $request)
    {
        $estado = $request->input('state'); // Ej: 'SUCCESS'
        $transaccionId = $request->input('transaction_id');
        $firebaseUid = $request->input('metadata.firebase_uid');
        $planNombre = $request->input('metadata.plan_name');
        $monto = $request->input('amount');

        if ($estado === 'SUCCESS') {
            return $this->procesarPagoExitoso($firebaseUid, $planNombre, $monto, 'LemonCash', $transaccionId);
        }

        return response()->json(['mensaje' => 'Pago pendiente en Lemon'], 200);
    }

    // 3. LA LÓGICA QUE HACE LA MAGIA Y PRENDE EL SERVER
    private function procesarPagoExitoso($firebaseUid, $planNombre, $monto, $metodo, $transaccionId)
    {
        $cliente = Cliente::where('firebase_uid', $firebaseUid)->first();
        $plan = Plan::where('nombre', $planNombre)->first();

        if (! $cliente || ! $plan || ! is_numeric($monto) || (float) $monto <= 0 || ! $transaccionId) {
            return response()->json(['mensaje' => 'Webhook inválido o cliente/plan inexistente.'], 422);
        }

        // Evitar procesar el mismo pago dos veces.
        $existe = Pago::where('transaccion_id', $transaccionId)->first();
        if ($existe) {
            if ($existe->estado !== 'completado') {
                $existe->update(['estado' => 'completado']);
            }

            return response()->json(['mensaje' => 'Pago ya procesado'], 200);
        }

        [$suscripcion] = DB::transaction(function () use ($cliente, $plan, $monto, $metodo, $transaccionId) {
            $suscripcion = Suscripcion::create([
                'cliente_id' => $cliente->id,
                'plan_id' => $plan->id,
                'estado' => 'activa',
                'ciclo_meses' => 1,
                'fecha_inicio' => now(),
                'fecha_vencimiento' => now()->addMonth(),
            ]);

            Pago::create([
                'suscripcion_id' => $suscripcion->id,
                'monto' => $monto,
                'pasarela_pago' => $metodo,
                'medio_pago' => 'no_definido',
                'estado' => 'completado',
                'transaccion_id' => $transaccionId,
            ]);

            $cliente->update(['plan_activo' => $plan->nombre]);

            return [$suscripcion];
        });

        // B. Le pegamos al Demonio de Node.js para que orqueste el contenedor en Docker
        try {
            Http::withHeaders([
                'x-daemon-secret' => (string) env('NODE_SECRET_KEY'),
            ])->post('http://backend:3000/api/internal/deploy', [
                'cliente_id' => $cliente->id,
                'plan' => $plan->nombre,
                'suscripcion_id' => $suscripcion->id,
            ])->throw();

            Log::info("🚀 Servidor desplegado exitosamente para $firebaseUid via $metodo");
        } catch (\Exception $e) {
            Log::error('❌ Error al contactar a Node.js: '.$e->getMessage());
        }

        return response()->json(['mensaje' => 'Pago procesado y servidor desplegado'], 200);
    }
}
