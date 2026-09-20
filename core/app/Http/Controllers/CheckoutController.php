<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Suscripcion;
use App\Models\Pago;
use Illuminate\Support\Facades\Http;

class CheckoutController extends Controller
{
    public function crearPago(Request $request)
    {
        $request->validate([
            'firebase_uid' => 'required|string',
            'plan_nombre'  => 'required|string',
            'ciclo_meses'  => 'required|integer',
            'monto'        => 'required|numeric'
        ]);

        $transaccionId = 'PRO-' . uniqid();

        // 1. Registramos el pago como pendiente en Microsoft SQL Server
        Pago::create([
            'firebase_uid'   => $request->firebase_uid,
            'monto'          => $request->monto,
            'metodo'         => 'astropay',
            'estado'         => 'pendiente',
            'transaccion_id' => $transaccionId
        ]);

        // 2. Registramos la suscripción en estado pendiente
        Suscripcion::create([
            'firebase_uid' => $request->firebase_uid,
            'plan_nombre'  => $request->plan_nombre,
            'ciclo_meses'  => $request->ciclo_meses,
            'estado'       => 'pendiente'
        ]);

        // 3. Conexión con la API de AstroPay (Sandbox)
        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . base64_encode(env('ASTROPAY_CLIENT_ID') . ':' . env('ASTROPAY_SECRET')),
            'Content-Type'  => 'application/json'
        ])->post(env('ASTROPAY_API_URL'), [
            'amount'            => $request->monto,
            'currency'          => 'ARS',
            'merchant_order_id' => $transaccionId,
            'country'           => 'AR',
            'user'              => [
                'email'         => $request->email ?? 'cliente@proservers.com.ar'
            ]
        ]);

        if ($response->successful()) {
            return response()->json([
                'success'        => true,
                'redirect_url'   => $response->json()['redirect_url'] ?? '#',
                'transaccion_id' => $transaccionId
            ]);
        }

        return response()->json([
            'success' => false,
            'error'   => 'No se pudo conectar con la pasarela de pagos de AstroPay'
        ], 500);
    }
}