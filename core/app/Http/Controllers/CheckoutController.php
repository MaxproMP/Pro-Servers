<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    // 1. WEBHOOK DE ASTROPAY (Recibe la confirmación de pago)
    public function webhookAstropay(Request $request)
    {
        // AstroPay manda el estado de la transacción acá
        $estado = $request->input('status'); // Ej: 'APPROVED'
        $transaccionId = $request->input('merchant_deposit_id');
        $firebaseUid = $request->input('user_id'); 
        $planNombre = $request->input('plan_name');
        $monto = $request->input('amount');

        if ($estado === 'APPROVED') {
            return $this->procesarPagoExitoso($firebaseUid, $planNombre, $monto, 'AstroPay', $transaccionId);
        }

        return response()->json(['mensaje' => 'Pago no aprobado aún'], 200);
    }

    // 2. WEBHOOK DE LEMONCASH (Recibe pago crypto/fiat)
    public function webhookLemoncash(Request $request)
    {
        // Validación de LemonCash
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
        // Evitar procesar el mismo pago dos veces
        $existe = DB::table('Pagos')->where('transaccion_id', $transaccionId)->first();
        if ($existe) {
            return response()->json(['mensaje' => 'Pago ya procesado'], 200);
        }

        // A. Guardamos la plata en tu Azure SQL
        DB::table('Pagos')->insert([
            'firebase_uid' => $firebaseUid,
            'monto' => $monto,
            'metodo' => $metodo,
            'estado' => 'completado',
            'transaccion_id' => $transaccionId,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('Suscripciones')->insert([
            'firebase_uid' => $firebaseUid,
            'plan_nombre' => $planNombre,
            'estado' => 'activa',
            'fecha_inicio' => now(),
            'fecha_vencimiento' => now()->addDays(30), // 1 mes de hosting
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // B. Le pegamos a TU Node.js para que orqueste el contenedor en Docker
        // Usamos la red interna de AWS (minecraft-net)
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_SECRET_KEY')
            ])->post('http://minecraft-panel-backend:3000/api/internal/deploy', [
                'firebase_uid' => $firebaseUid,
                'plan' => $planNombre
            ]);
            
            Log::info("🚀 Servidor desplegado exitosamente para $firebaseUid via $metodo");
        } catch (\Exception $e) {
            Log::error("❌ Error al contactar a Node.js: " . $e->getMessage());
        }

        return response()->json(['mensaje' => 'Pago procesado y servidor desplegado'], 200);
    }
}
