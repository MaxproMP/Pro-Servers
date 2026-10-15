<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NodeProxyController extends Controller
{
    private string $nodeUrl = 'http://minecraft-panel-backend:3000';

    /** @return array{nombre: string, memoria_mb: int, cpu_cores: int, almacenamiento_gb: int, max_jugadores: int|null}|null */
    private function limitesDelPlan($cliente): ?array
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

    public function proxyToNode(Request $request, $path)
    {
        // Validación de acciones delegada por Laravel
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        // Agregar el UID del cliente a los datos (para mantener retrocompatibilidad con el demonio Node)
        $data = $request->all();
        $data['uid'] = (string) ($user->firebase_uid ?: $user->getAuthIdentifier());
        // Los límites de hardware salen del plan en SQL Server; lo que envíe el navegador se descarta.
        unset($data['plan']);
        if ($request->isMethod('post') && $request->path() === 'api/project/create') {
            $data['plan'] = $this->limitesDelPlan($user);
        }
        // Node guarda los proyectos bajo el UID de Firebase, no bajo el ID local de clientes.

        // Node valida el token de Firebase por su cuenta: hay que reenviarlo.
        $response = Http::withToken((string) $request->bearerToken())->withHeaders([
            'X-Forwarded-For' => $request->ip()
        ]);

        if ($request->isMethod('get')) {
            $fullPath = $request->path(); // e.g. api/project/create
        $response = $response->get($this->nodeUrl . '/' . $fullPath, $data);
        } elseif ($request->isMethod('post')) {
            $fullPath = $request->path();
        $response = $response->post($this->nodeUrl . '/' . $fullPath, $data);
        } elseif ($request->isMethod('put')) {
            $fullPath = $request->path();
        $response = $response->put($this->nodeUrl . '/' . $fullPath, $data);
        } elseif ($request->isMethod('delete')) {
            $fullPath = $request->path();
        $response = $response->delete($this->nodeUrl . '/' . $fullPath, $data);
        }

        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type'));
    }
}
