<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhooks de pago: firma HMAC-SHA256 del cuerpo crudo en X-Webhook-Signature.
 * Falla cerrado: sin WEBHOOK_SECRET configurado, ningún webhook puede activar suscripciones.
 */
final class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.webhooks.secret');
        $firma = (string) $request->header('X-Webhook-Signature');
        $esperada = hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || $firma === '' || ! hash_equals($esperada, strtolower($firma))) {
            return response()->json(['error' => 'Firma de webhook inválida.'], 403);
        }

        return $next($request);
    }
}
