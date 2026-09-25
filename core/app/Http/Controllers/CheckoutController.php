<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Suscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CheckoutController extends Controller
{
    public function crearPago(Request $request, string $pasarela): JsonResponse
    {
        $request->validate([
            'plan_nombre' => 'required|string',
            'ciclo_meses' => 'required|integer|min:1',
        ]);

        $pasarela = strtolower($pasarela);
        if (! in_array($pasarela, ['astropay', 'uala', 'openpay'], true)) {
            return response()->json(['success' => false, 'error' => 'Pasarela no soportada.'], 422);
        }

        if ($pasarela !== 'astropay') {
            return response()->json(['success' => false, 'error' => 'Esta pasarela requiere credenciales productivas configuradas.'], 503);
        }

        $firebaseUid = (string) $request->attributes->get('firebase_uid');
        $transaccionId = 'PRO-'.uniqid();
        $cliente = Cliente::where('firebase_uid', $firebaseUid)->firstOrFail();
        $plan = Plan::where('nombre', strtolower((string) $request->input('plan_nombre')))->firstOrFail();
        $ciclo = (int) $request->input('ciclo_meses');
        $discounts = [1 => 0, 3 => 10, 6 => 15, 12 => 20];
        $discount = $discounts[$ciclo] ?? 0;
        $monto = round((float) $plan->precio_mensual * $ciclo * (1 - ($discount / 100)), 2);

        [$suscripcion, $pago] = DB::transaction(function () use ($cliente, $plan, $ciclo, $monto, $pasarela, $transaccionId) {
            $suscripcion = Suscripcion::create([
                'cliente_id' => $cliente->id,
                'plan_id' => $plan->id,
                'ciclo_meses' => $ciclo,
                'estado' => 'pendiente',
            ]);

            $pago = Pago::create([
                'suscripcion_id' => $suscripcion->id,
                'monto' => $monto,
                'pasarela_pago' => $pasarela,
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
            'amount' => $monto,
            'currency' => 'USD',
            'merchant_order_id' => $transaccionId,
            'country' => 'AR',
            'user' => [
                'email' => $request->input('email', $cliente->email),
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
