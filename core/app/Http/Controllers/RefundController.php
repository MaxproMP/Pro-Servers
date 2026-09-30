<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditoriaReembolso;
use App\Models\Cliente;
use App\Models\Pago;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class RefundController extends Controller
{
    public function solicitarReembolso(Request $request, int $pagoId): JsonResponse
    {
        $data = $request->validate([
            'motivo_solicitud' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $firebaseUid = (string) $request->attributes->get('firebase_uid');
        $cliente = Cliente::where('firebase_uid', $firebaseUid)->firstOrFail();
        $pago = Pago::with('suscripcion')
            ->whereKey($pagoId)
            ->whereHas('suscripcion', fn ($query) => $query->where('cliente_id', $cliente->id))
            ->firstOrFail();

        if ($pago->estado !== 'completado') {
            return response()->json(['error' => 'Solo se pueden reembolsar pagos completados.'], 422);
        }

        $fechaPago = CarbonImmutable::parse($pago->fecha_transaccion ?? $pago->created_at);
        if (CarbonImmutable::now()->greaterThan($fechaPago->addDays(30))) {
            AuditoriaReembolso::create([
                'pago_id' => $pago->id,
                'cliente_id' => $cliente->id,
                'motivo_solicitud' => $data['motivo_solicitud'],
                'monto_devuelto' => $pago->monto,
                'estado_auditoria' => 'rechazado',
            ]);

            return response()->json(['error' => 'El plazo de reembolso de 30 días ha expirado.'], 422);
        }

        $auditoria = DB::transaction(function () use ($pago, $cliente, $data): AuditoriaReembolso {
            $pago->update(['estado' => 'reembolsado']);

            return AuditoriaReembolso::create([
                'pago_id' => $pago->id,
                'cliente_id' => $cliente->id,
                'motivo_solicitud' => $data['motivo_solicitud'],
                'monto_devuelto' => $pago->monto,
                'estado_auditoria' => 'solicitado',
            ]);
        });

        try {
            Http::timeout(15)
                ->withHeaders(['x-daemon-secret' => (string) config('services.daemon.secret')])
                ->post('http://backend:3000/daemon/server/destroy-forced', [
                    'firebase_uid' => $firebaseUid,
                    'cliente_id' => $cliente->id,
                    'motivo' => $data['motivo_solicitud'],
                ])
                ->throw();

            $auditoria->update(['estado_auditoria' => 'procesado']);
        } catch (\Throwable $error) {
            $auditoria->update(['estado_auditoria' => 'fallido']);

            return response()->json([
                'error' => 'El reembolso quedó registrado, pero no se pudo purgar la infraestructura.',
                'auditoria_id' => $auditoria->id,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reembolso aprobado y servidores purgados.',
            'auditoria_id' => $auditoria->id,
        ], 202);
    }

    public function historial(Request $request): JsonResponse
    {
        $cliente = Cliente::where('firebase_uid', (string) $request->attributes->get('firebase_uid'))->first();
        abort_unless($cliente && $cliente->role === 'ceo', 403);

        return response()->json(AuditoriaReembolso::with(['pago', 'cliente'])
            ->latest()
            ->paginate(50));
    }
}
