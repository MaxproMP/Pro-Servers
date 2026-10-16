<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\TicketSoporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Inicia un pago: valida plan y ciclo, calcula el monto en servidor y redirige a la pasarela.
 * La suscripción queda "pendiente" hasta que un webhook verificado (o el Panel Admin) confirme el pago.
 */
class CheckoutController extends Controller
{
    /** Descuentos por ciclo: los mismos que muestran planes.html y checkout.html. */
    public const DESCUENTOS = [1 => 0, 3 => 10, 6 => 15, 12 => 20];

    /** Qué pasarelas están configuradas de verdad en este servidor. */
    public static function pasarelasActivas(): array
    {
        // Solo se ofrece una pasarela si además puede CONFIRMAR pagos (secret de webhook cargado).
        $astroUrl = (string) config('services.astropay.api_url');

        $mp = filled(config('services.mercadopago.token')) && (float) config('services.mercadopago.tasa_ars') > 0
            && filled(config('services.mercadopago.webhook_secret'));

        return [
            'mercadopago' => $mp,
            'tarjeta' => $mp, // crédito o débito por el Checkout Pro de Mercado Pago, limitado a tarjetas
            // Google Pay nativo exige una pasarela con tokenización de Google Pay y merchant ID: hasta tenerla, va asistido.
            'googlepay' => false,
            'astropay' => $astroUrl !== '' && filled(config('services.astropay.client_id')) && filled(config('services.astropay.secret'))
                && filled(config('services.webhooks.secret'))
                && ! (app()->isProduction() && str_contains($astroUrl, 'sandbox')), // nunca cobrar en sandbox en producción
            'uala' => false, // Ualá Bis: integración pendiente de credenciales y API oficial.
        ];
    }

    public function pasarelas(): JsonResponse
    {
        $tasa = (float) config('services.mercadopago.tasa_ars');

        return response()->json([
            'success' => true,
            'pasarelas' => self::pasarelasActivas(),
            'tasa_ars' => $tasa > 0 ? $tasa : null,
            'descuentos' => self::DESCUENTOS,
            'asistidos' => self::ASISTIDOS,
        ]);
    }

    public function crearPago(Request $request, string $pasarela): JsonResponse
    {
        $data = $request->validate([
            'plan_nombre' => ['required', 'string', 'max:40'],
            'ciclo_meses' => ['required', 'integer', Rule::in(array_keys(self::DESCUENTOS))],
        ]);

        $pasarela = strtolower($pasarela);
        $activas = self::pasarelasActivas();
        if (! array_key_exists($pasarela, $activas)) {
            return response()->json(['success' => false, 'error' => 'Pasarela no soportada.'], 422);
        }
        if (! $activas[$pasarela]) {
            return response()->json(['success' => false, 'error' => 'Este medio de pago todavía no está disponible.'], 503);
        }

        $cliente = $request->user();
        $plan = Plan::where('nombre', strtolower($data['plan_nombre']))->where('visible', true)->first();
        if (! $plan) {
            return response()->json(['success' => false, 'error' => 'Ese plan no está disponible.'], 422);
        }

        $ciclo = (int) $data['ciclo_meses'];
        $monto = round((float) $plan->precio_mensual * $ciclo * (1 - self::DESCUENTOS[$ciclo] / 100), 2);
        $transaccionId = 'PRO-'.Str::upper(Str::random(16));

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
                // "tarjeta" se cobra por Mercado Pago: la pasarela queda registrada como tal (la usa su webhook).
                'pasarela_pago' => $pasarela === 'tarjeta' ? 'mercadopago' : $pasarela,
                'medio_pago' => $pasarela === 'tarjeta' ? 'tarjeta' : 'no_definido',
                'estado' => 'pendiente',
                'transaccion_id' => $transaccionId,
            ]);

            return [$suscripcion, $pago];
        });

        $redirect = in_array($pasarela, ['mercadopago', 'tarjeta'], true)
            ? $this->mercadoPago($plan, $ciclo, $monto, $transaccionId, $cliente->email, $pasarela === 'tarjeta')
            : $this->astroPay($monto, $transaccionId, $cliente->email);

        if (! $redirect) {
            $pago->update(['estado' => 'fallido']);
            $suscripcion->update(['estado' => 'cancelada']);

            return response()->json(['success' => false, 'error' => 'La pasarela no respondió. Probá de nuevo en unos minutos.'], 502);
        }

        return response()->json([
            'success' => true,
            'redirect_url' => $redirect,
            'transaccion_id' => $transaccionId,
            'monto_usd' => $monto,
            'monto_ars' => in_array($pasarela, ['mercadopago', 'tarjeta'], true) ? round($monto * (float) config('services.mercadopago.tasa_ars'), 2) : null,
        ]);
    }

    /** Medios con cobro asistido: el staff envía el link (o los datos de transferencia) y confirma desde el Panel Admin. */
    public const ASISTIDOS = [
        'tarjeta' => 'Tarjeta de crédito o débito', 'googlepay' => 'Google Pay', 'mercadopago' => 'Mercado Pago',
        'uala' => 'Ualá Bis', 'astropay' => 'AstroPay', 'transferencia' => 'Transferencia bancaria',
    ];

    /** Medio de pago (columna medio_pago) de cada pedido asistido; el staff lo corrige al confirmar si hace falta. */
    private const MEDIO_ASISTIDO = ['tarjeta' => 'tarjeta', 'googlepay' => 'tarjeta', 'transferencia' => 'transferencia'];

    /**
     * Pago asistido: deja la suscripción y el pago "pendiente" en SQL Server y abre un ticket con la referencia.
     * El Panel Admin lo confirma con /api/admin/accounting/confirm, que activa el plan igual que un webhook.
     */
    public function asistido(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_nombre' => ['required', 'string', 'max:40'],
            'ciclo_meses' => ['required', 'integer', Rule::in(array_keys(self::DESCUENTOS))],
            'medio' => ['required', Rule::in(array_keys(self::ASISTIDOS))],
        ]);
        $cliente = $request->user();
        $plan = Plan::where('nombre', strtolower($data['plan_nombre']))->where('visible', true)->first();
        if (! $plan) {
            return response()->json(['success' => false, 'error' => 'Ese plan no está disponible.'], 422);
        }
        $ciclo = (int) $data['ciclo_meses'];
        $monto = round((float) $plan->precio_mensual * $ciclo * (1 - self::DESCUENTOS[$ciclo] / 100), 2);

        // Un pedido pendiente por cliente, plan, ciclo y medio: reintentar no duplica cobros.
        $pago = Pago::where('pasarela_pago', $data['medio'])->where('estado', 'pendiente')->where('transaccion_id', 'like', 'ASI-%')
            ->whereHas('suscripcion', fn ($q) => $q->where('cliente_id', $cliente->id)->where('plan_id', $plan->id)->where('ciclo_meses', $ciclo)->where('estado', 'pendiente'))
            ->where('created_at', '>=', now()->subDay())->first();

        if (! $pago) {
            $pago = DB::transaction(function () use ($cliente, $plan, $ciclo, $monto, $data) {
                $suscripcion = Suscripcion::create(['cliente_id' => $cliente->id, 'plan_id' => $plan->id, 'ciclo_meses' => $ciclo, 'estado' => 'pendiente']);
                $pago = Pago::create([
                    'suscripcion_id' => $suscripcion->id,
                    'monto' => $monto,
                    'pasarela_pago' => $data['medio'],
                    'medio_pago' => self::MEDIO_ASISTIDO[$data['medio']] ?? 'no_definido',
                    'estado' => 'pendiente',
                    'transaccion_id' => 'ASI-'.Str::upper(Str::random(12)),
                ]);
                $ticket = TicketSoporte::firstOrCreate(['cliente_id' => $cliente->id, 'estado' => 'abierto']);
                $ticket->mensajes()->create(['remitente' => 'user', 'mensaje' => sprintf(
                    'Pedido de pago %s · Plan %s · %d %s · USD %s · Medio: %s. Envíenme el link o los datos para pagar.',
                    $pago->transaccion_id, ucfirst($plan->nombre), $ciclo, $ciclo === 1 ? 'mes' : 'meses', number_format($monto, 2, '.', ''), self::ASISTIDOS[$data['medio']]
                )]);

                return $pago;
            });
        }

        return response()->json([
            'success' => true,
            'transaccion_id' => $pago->transaccion_id,
            'monto_usd' => (float) $pago->monto,
            'medio' => self::ASISTIDOS[$data['medio']],
        ], 201);
    }

    private function mercadoPago(Plan $plan, int $ciclo, float $monto, string $tx, ?string $email, bool $soloTarjetas = false): ?string
    {
        $ars = round($monto * (float) config('services.mercadopago.tasa_ars'), 2);
        $volver = rtrim((string) config('app.url'), '/').'/checkout.html?plan='.urlencode($plan->nombre).'&ciclo='.$ciclo.'&estado=';

        $r = Http::withToken((string) config('services.mercadopago.token'))->timeout(20)
            ->post('https://api.mercadopago.com/checkout/preferences', [
                'items' => [[
                    'title' => 'ProServers Plan '.ucfirst($plan->nombre).' x '.$ciclo.' '.($ciclo === 1 ? 'mes' : 'meses'),
                    'quantity' => 1,
                    'currency_id' => 'ARS',
                    'unit_price' => $ars,
                ]],
                'payer' => ['email' => $email],
                'external_reference' => $tx,
                'notification_url' => rtrim((string) config('app.url'), '/').'/api/webhooks/mercadopago',
                'back_urls' => ['success' => $volver.'ok', 'failure' => $volver.'error', 'pending' => $volver.'pendiente'],
                'auto_return' => 'approved',
                // Medio "Tarjeta": el checkout muestra solo crédito y débito (sin efectivo ni cajeros).
                ...($soloTarjetas ? ['payment_methods' => ['excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']]]] : []),
            ]);

        return $r->successful() ? ($r->json('init_point') ?: null) : null;
    }

    private function astroPay(float $monto, string $tx, ?string $email): ?string
    {
        $r = Http::withBasicAuth((string) config('services.astropay.client_id'), (string) config('services.astropay.secret'))
            ->timeout(20)
            ->post((string) config('services.astropay.api_url'), [
                'amount' => $monto,
                'currency' => 'USD',
                'merchant_order_id' => $tx,
                'country' => 'AR',
                'callback_url' => rtrim((string) config('app.url'), '/').'/api/pagos/webhook/astropay',
                'user' => ['email' => $email],
            ]);

        return $r->successful() ? ($r->json('redirect_url') ?: $r->json('url') ?: null) : null;
    }
}
