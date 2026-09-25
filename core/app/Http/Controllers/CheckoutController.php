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

class CheckoutController extends Controller
{
    public function crearPago(Request $request)
    {
        $request->validate([
            'firebase_uid' => 'required|string',
            'plan_nombre' => 'required|string',
            'ciclo_meses' => 'required|integer|min:1',
            'monto' => 'required|numeric|gt:0',
        ]);

        $transaccionId = 'PRO-'.uniqid();
        $cliente = Cliente::where('firebase_uid', $request->string('firebase_uid'))->firstOrFail();
        $plan = Plan::where('nombre', $request->string('plan_nombre'))->firstOrFail();

        [$suscripcion, $pago] = DB::transaction(function () use ($cliente, $plan, $request, $transaccionId) {
            $suscripcion = Suscripcion::create([
                'cliente_id' => $cliente->id,
                'plan_id' => $plan->id,
                'ciclo_meses' => (int) $request->input('ciclo_meses'),
                'estado' => 'pendiente',
            ]);

            $pago = Pago::create([
                'suscripcion_id' => $suscripcion->id,
                'monto' => $request->input('monto'),
                'pasarela_pago' => 'astropay',
                'medio_pago' => 'no_definido',
                'estado' => 'pendiente',
                'transaccion_id' => $transaccionId,
            ]);

            return [$suscripcion, $pago];
        });

        // 3. Conexión con la API de AstroPay (Sandbox)
        $response = Http::withHeaders([
            'Authorization' => 'Basic '.base64_encode(env('ASTROPAY_CLIENT_ID').':'.env('ASTROPAY_SECRET')),
            'Content-Type' => 'application/json',
        ])->post(env('ASTROPAY_API_URL'), [
            'amount' => $request->monto,
            'currency' => 'ARS',
            'merchant_order_id' => $transaccionId,
            'country' => 'AR',
            'user' => [
                'email' => $request->email ?? 'cliente@proservers.com.ar',
            ],
        ]);

        if ($response->successful()) {
            return response()->json([
                'success' => true,
                'redirect_url' => $response->json()['redirect_url'] ?? '#',
                'transaccion_id' => $transaccionId,
                'suscripcion_id' => $suscripcion->id,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'No se pudo conectar con la pasarela de pagos de AstroPay',
        ], 500);
    }
}
