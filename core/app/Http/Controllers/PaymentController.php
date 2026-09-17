<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
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
        'ghost-warrior' => 85.00
    ];

    public function procesarAstroPay(Request $request)
    {
        // 2. Recibimos lo que manda el HTML oculto
        $planSeleccionado = strtolower($request->input('plan', 'redstone'));
        $ciclo = (int) $request->input('ciclo', 1);

        if (!array_key_exists($planSeleccionado, $this->preciosBase)) {
            return response()->json(['error' => 'El plan seleccionado no es válido: ' . $planSeleccionado], 400);
        }

        // 3. Calculamos los descuentos igual que en el frontend
        $descuentos = [1 => 0, 3 => 10, 6 => 15, 12 => 20];
        $porcentajeDescuento = $descuentos[$ciclo] ?? 0;
        
        $precioBaseMensual = $this->preciosBase[$planSeleccionado];
        $precioMensualConDescuento = $precioBaseMensual * (1 - ($porcentajeDescuento / 100));
        $totalACobrar = round($precioMensualConDescuento * $ciclo, 2);

        $transactionId = 'PRO-' . Str::upper(Str::random(6));
        
        $firebaseUid = $request->input('uid', 'usuario_anonimo'); 
        $planId = DB::table('planes')->where('nombre', $planSeleccionado)->value('id') ?? 1;

        // 4. Guardamos la suscripción y el pago en SQL Server como "pendiente"
        $suscripcionId = DB::table('Suscripciones')->insertGetId([
            'user_id'           => 1, 
            'firebase_uid'      => $firebaseUid,
            'plan_id'           => $planId,
            'plan_nombre'       => $planSeleccionado,
            'ciclo_meses'       => $ciclo,
            'estado'            => 'suspendida',
            'fecha_inicio'      => now(),
            'fecha_vencimiento' => now()->addMonths($ciclo),
            'created_at'        => now(),
            'updated_at'        => now()
        ]);

        DB::table('Pagos')->insert([
            'suscripcion_id' => $suscripcionId,
            'firebase_uid'   => $firebaseUid,
            'monto'          => $totalACobrar,
            'pasarela_pago'  => 'astropay',
            'medio_pago'     => 'no_definido',
            'metodo'         => 'astropay',
            'estado'         => 'pendiente',
            'transaccion_id' => $transactionId,
            'fecha'          => now(),
            'created_at'     => now(),
            'updated_at'     => now()
        ]);

        // 5. Armamos la firma y mandamos la orden a AstroPay
        $clientId = env('ASTROPAY_CLIENT_ID');
        $secret = env('ASTROPAY_SECRET');
        $apiUrl = env('ASTROPAY_API_URL', 'https://api.astropay.com/v1/sandbox/payments');

        $signature = hash_hmac('sha256', $clientId . $transactionId, $secret);

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
            'cuerpo_crudo' => $response->body()
        ], $response->status() === 0 ? 500 : $response->status());
    }

    public function webhookAstroPay(Request $request)
    {
        $transactionId = $request->input('merchant_invoice_id');
        $status = $request->input('status'); 

        if ($status === 'APPROVED') {
            $pago = DB::table('Pagos')->where('transaccion_id', $transactionId)->first();
            
            if ($pago) {
                DB::table('Pagos')->where('id', $pago->id)->update(['estado' => 'completado']);
                
                $suscripcion = DB::table('Suscripciones')->where('id', $pago->suscripcion_id)->first();
                if ($suscripcion) {
                    DB::table('Suscripciones')->where('id', $suscripcion->id)->update(['estado' => 'activa']);

                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_SECRET_KEY', 'clave_super_secreta_123')
                ])->post('http://127.0.0.1:3000/api/internal/upgrade-plan', [
                    'uid' => $suscripcion->firebase_uid,
                    'newPlan' => $suscripcion->plan_nombre
                ]);
                }
            }
        }

        return response()->json(['status' => 'recibido']);
    }
} 