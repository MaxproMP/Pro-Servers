<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    // 1. Diccionario de precios oficiales (en dólares)
    private $preciosBase = [
        'redstone' => 6.00,
        'hierro' => 9.00,
        'cobre' => 12.00,
        'oro' => 15.00,
        'diamante' => 25.00,
        'netherite' => 45.00,
        'ghost-warrior' => 85.00,
    ];

    public function procesarAstroPay(Request $request)
    {
        // 2. Recibimos lo que manda el HTML oculto
        $planSeleccionado = strtolower($request->input('plan', 'redstone'));
        $ciclo = (int) $request->input('ciclo', 1);

        if (! array_key_exists($planSeleccionado, $this->preciosBase)) {
            return response()->json(['error' => 'El plan seleccionado no es válido: '.$planSeleccionado], 400);
        }

        // 3. Calculamos los descuentos igual que en el frontend
        $descuentos = [1 => 0, 3 => 10, 6 => 15, 12 => 20];
        $porcentajeDescuento = $descuentos[$ciclo] ?? 0;

        $precioBaseMensual = $this->preciosBase[$planSeleccionado];
        $precioMensualConDescuento = $precioBaseMensual * (1 - ($porcentajeDescuento / 100));
        $totalACobrar = round($precioMensualConDescuento * $ciclo, 2);

        $transactionId = 'PRO-'.Str::upper(Str::random(6));

        $firebaseUid = (string) $request->input('uid', '');
        $cliente = Cliente::where('firebase_uid', $firebaseUid)->first();
        $plan = Plan::where('nombre', $planSeleccionado)->first();

        if (! $cliente || ! $plan) {
            return response()->json(['error' => 'Cliente o plan no encontrado.'], 404);
        }

        // 4. Guardamos la suscripción y el pago en SQL Server como "pendiente"
        $suscripcionId = DB::table('suscripciones')->insertGetId([
            'cliente_id' => $cliente->id,
            'plan_id' => $plan->id,
            'ciclo_meses' => $ciclo,
            'estado' => 'suspendida',
            'fecha_inicio' => now(),
            'fecha_vencimiento' => now()->addMonths($ciclo),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pagos')->insert([
            'suscripcion_id' => $suscripcionId,
            'monto' => $totalACobrar,
            'pasarela_pago' => 'astropay',
            'medio_pago' => 'no_definido',
            'estado' => 'pendiente',
            'transaccion_id' => $transactionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Armamos la firma y mandamos la orden a AstroPay
        $clientId = env('ASTROPAY_CLIENT_ID');
        $secret = env('ASTROPAY_SECRET');
        $apiUrl = env('ASTROPAY_API_URL', 'https://api.astropay.com/v1/sandbox/payments');

        $signature = hash_hmac('sha256', $clientId.$transactionId, $secret);

        $response = Http::withHeaders([
            'Merchant-Id' => $clientId,
            'Signature' => $signature,
        ])->post($apiUrl, [
            'merchant_invoice_id' => $transactionId,
            'amount' => $totalACobrar,
            'currency' => 'USD',
            'callback_url' => url('/api/pagos/webhook/astropay'),
        ]);

        if ($response->successful()) {
            return redirect($response->json('url') ?? $response->json('payment_url') ?? url('/'));
        }

        // BLOQUE DE DEBUG EXACTO
        return response()->json([
            'error' => 'Fallo al conectar con AstroPay',
            'http_status' => $response->status(),
            'client_id_detectado' => $clientId ? 'Sí, cargado' : 'VACÍO (Reiniciar servidor Laravel)',
            'url_consultada' => $apiUrl,
            'cuerpo_crudo' => $response->body(),
        ], $response->status() === 0 ? 500 : $response->status());
    }

    public function webhookAstroPay(Request $request)
    {
        $transactionId = $request->input('merchant_invoice_id');
        $status = $request->input('status');

        if ($status === 'APPROVED') {
            $pago = DB::table('pagos')->where('transaccion_id', $transactionId)->first();

            if ($pago) {
                DB::table('pagos')->where('id', $pago->id)->update(['estado' => 'completado']);

                $suscripcion = DB::table('suscripciones')->where('id', $pago->suscripcion_id)->first();
                if ($suscripcion) {
                    DB::table('suscripciones')->where('id', $suscripcion->id)->update(['estado' => 'activa']);
                    $plan = DB::table('planes')->where('id', $suscripcion->plan_id)->value('nombre');

                    Http::withHeaders([
                        'x-daemon-secret' => (string) env('NODE_SECRET_KEY'),
                    ])->post('http://backend:3000/api/internal/upgrade-plan', [
                        'cliente_id' => $suscripcion->cliente_id,
                        'plan' => $plan,
                    ]);
                }
            }
        }

        return response()->json(['status' => 'recibido']);
    }
}
