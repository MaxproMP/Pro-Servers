<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Services\ActivadorPagos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de AstroPay. La firma HMAC (X-Webhook-Signature) ya la validó VerifyWebhookSignature.
 * Solo confirma pagos que ProServers creó en el checkout: nunca crea suscripciones desde el payload.
 */
class PaymentController extends Controller
{
    public function webhookAstroPay(Request $request, ActivadorPagos $activador): JsonResponse
    {
        $tx = (string) ($request->input('merchant_invoice_id') ?? $request->input('merchant_order_id') ?? $request->input('merchant_deposit_id') ?? '');
        if (strtoupper((string) $request->input('status')) !== 'APPROVED' || $tx === '') {
            return response()->json(['status' => 'ignorado']);
        }

        $pago = Pago::where('transaccion_id', $tx)->where('pasarela_pago', 'astropay')->first();
        if (! $pago) {
            return response()->json(['status' => 'desconocido']);
        }

        if ($request->filled('amount') && abs((float) $request->input('amount') - (float) $pago->monto) > 0.01) {
            Log::warning('AstroPay: el monto no coincide', ['pago' => $pago->id, 'recibido' => $request->input('amount')]);

            return response()->json(['status' => 'monto no coincide'], 422);
        }

        $activador->completar($pago);

        return response()->json(['status' => 'recibido']);
    }
}
