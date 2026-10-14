<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NodeProxyController extends Controller
{
    private string $nodeUrl = 'http://minecraft-panel-backend:3000';

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

    public function getAdminServers(Request $request)
    {
        // Validado por middleware 'admin' en la ruta
        $response = Http::withToken((string) $request->bearerToken())->get($this->nodeUrl . '/api/admin/all-servers');
        return response($response->body(), $response->status())
            ->header('Content-Type', $response->header('Content-Type'));
    }

    public function getAdminStats(Request $request)
    {
        // Validado por middleware 'admin'
        $totalClientes = \App\Models\Cliente::count();
        $dineroTotal = \App\Models\Pago::sum('monto');
        
        $responseNode = Http::withToken((string) $request->bearerToken())->get($this->nodeUrl . '/api/admin/all-servers');
        $servers = $responseNode->json()['servers'] ?? [];

        return response()->json([
            'totalServidores' => count($servers),
            'totalUsuariosMongo' => $totalClientes, // compatibilidad front
            'totalClientes' => $totalClientes,
            'dineroTotal' => $dineroTotal,
            'success' => true
        ]);
    }
}
