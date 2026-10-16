<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Services\ActivadorPagos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notificaciones de Mercado Pago.
 * 1) Verifica x-signature (HMAC-SHA256 del manifiesto id/request-id/ts con MP_WEBHOOK_SECRET).
 * 2) No confía en el cuerpo: consulta el pago a la API de MP con el access token.
 * 3) Exige status approved, external_reference conocido y monto suficiente.
 */
final class MercadoPagoWebhookController extends Controller
{
    private const MEDIOS = [
        'credit_card' => 'tarjeta', 'debit_card' => 'tarjeta', 'prepaid_card' => 'tarjeta',
        'account_money' => 'saldo', 'bank_transfer' => 'transferencia', 'ticket' => 'efectivo', 'atm' => 'efectivo',
    ];

    public function handle(Request $request, ActivadorPagos $activador): JsonResponse
    {
        $dataId = (string) ($request->input('data.id') ?? $request->query('data_id') ?? '');
        if (! $this->firmaValida($request, $dataId)) {
            return response()->json(['error' => 'Firma inválida.'], 403);
        }

        $tipo = (string) ($request->input('type') ?? $request->query('type') ?? $request->query('topic') ?? '');
        if ($tipo !== 'payment' || ! ctype_digit($dataId)) {
            return response()->json(['status' => 'ignorado']);
        }

        $mp = Http::withToken((string) config('services.mercadopago.token'))->timeout(15)
            ->get("https://api.mercadopago.com/v1/payments/{$dataId}");
        if (! $mp->successful()) {
            return response()->json(['error' => 'No se pudo consultar el pago.'], 502); // MP reintenta
        }

        $pago = Pago::where('transaccion_id', (string) $mp->json('external_reference'))
            ->where('pasarela_pago', 'mercadopago')->first();
        if (! $pago || $mp->json('status') !== 'approved') {
            return response()->json(['status' => 'sin cambios']);
        }

        $esperado = round((float) $pago->monto * (float) config('services.mercadopago.tasa_ars'), 2);
        if ($mp->json('currency_id') !== 'ARS' || (float) $mp->json('transaction_amount') < $esperado * 0.95) {
            Log::warning('MP: monto o moneda no coinciden', ['pago' => $pago->id, 'mp' => $dataId, 'esperado' => $esperado, 'recibido' => $mp->json('transaction_amount')]);

            return response()->json(['status' => 'monto no coincide'], 422);
        }

        $activador->completar($pago, self::MEDIOS[$mp->json('payment_type_id')] ?? 'no_definido');

        return response()->json(['status' => 'ok']);
    }

    private function firmaValida(Request $request, string $dataId): bool
    {
        $secret = (string) config('services.mercadopago.webhook_secret');
        if ($secret === '' || $dataId === '') {
            return false;
        }
        $partes = [];
        foreach (explode(',', (string) $request->header('x-signature')) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $partes[$k] = $v;
        }
        if (empty($partes['ts']) || empty($partes['v1'])) {
            return false;
        }
        $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $manifiesto = "id:{$id};request-id:".$request->header('x-request-id').";ts:{$partes['ts']};";

        return hash_equals(hash_hmac('sha256', $manifiesto, $secret), $partes['v1']);
    }
}
