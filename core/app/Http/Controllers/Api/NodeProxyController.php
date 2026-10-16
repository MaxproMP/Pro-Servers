<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Único camino del navegador hacia el demonio Node.
 * Laravel firma cada llamada (x-daemon-secret) y declara quién es el actor (rol y plan);
 * Node toma el uid del token de Firebase y valida la propiedad de cada servidor con sus datos.
 */
class NodeProxyController extends Controller
{
    private string $nodeUrl = 'http://minecraft-panel-backend:3000';

    /** Rutas que crean contenedores: reciben los límites del plan y exigen email verificado. */
    private const RUTAS_DEPLOY = ['api/project/create', 'api/project/clone'];

    /** Funciones premium (plan Oro o superior, o staff). */
    private const RUTAS_PREMIUM = ['api/server/mine-ai'];

    /** @return array{nombre: string, memoria_mb: int, cpu_cores: int, almacenamiento_gb: int, max_jugadores: int|null}|null */
    private function limitesDelPlan(Cliente $cliente): ?array
    {
        $plan = $cliente->suscripcionActiva()->with('plan')->first()?->plan;

        return $plan ? [
            'nombre' => $plan->nombre,
            'memoria_mb' => (int) $plan->memoria_mb,
            'cpu_cores' => (int) $plan->cpu_cores,
            'almacenamiento_gb' => (int) $plan->almacenamiento_gb,
            'max_jugadores' => $plan->max_jugadores === null ? null : (int) $plan->max_jugadores,
        ] : null;
    }

    public function proxyToNode(Request $request, string $path)
    {
        /** @var Cliente|null $user */
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        $ruta = $request->path();
        $esStaff = in_array($user->role, ['ceo', 'admin', 'soporte'], true);
        $planNombre = $user->suscripcionActiva()->with('plan')->first()?->plan?->nombre;

        if (in_array($ruta, self::RUTAS_PREMIUM, true) && ! $esStaff && ! in_array($planNombre, config('services.planes_premium'), true)) {
            return response()->json(['success' => false, 'error' => 'Disponible desde el Plan Oro.'], 403);
        }

        // El uid y los límites nunca los define el navegador.
        $data = $request->except(['uid', 'plan']);
        if (in_array($ruta, self::RUTAS_DEPLOY, true)) {
            if ($request->attributes->get('email_verified') !== true) {
                return response()->json(['success' => false, 'message' => 'Verificá tu email antes de desplegar un servidor.'], 403);
            }
            $data['plan'] = $this->limitesDelPlan($user);
        }

        $secret = (string) config('services.daemon.secret');
        if ($secret === '') {
            report(new \RuntimeException('NODE_SECRET_KEY no configurado: el proxy hacia Node queda cerrado.'));

            return response()->json(['error' => 'Servicio no disponible.'], 503);
        }

        $metodo = strtoupper($request->method());
        $response = Http::withToken((string) $request->bearerToken())
            ->withHeaders([
                'x-daemon-secret' => $secret,
                'x-staff-role' => $esStaff ? $user->role : 'usuario',
                'x-plan' => (string) $planNombre,
                'X-Forwarded-For' => $request->ip(),
            ])
            ->timeout(60)
            ->send($metodo, $this->nodeUrl.'/'.$ruta, $metodo === 'GET' ? ['query' => $data] : ['json' => $data]);

        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type') ?: 'application/json');
    }
}
