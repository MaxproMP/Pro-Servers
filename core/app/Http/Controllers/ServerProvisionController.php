<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http; // Importante: usar el cliente HTTP de Laravel
use App\Models\Server; // Tu modelo de Azure SQL

class ServerProvisionController extends Controller
{
    public function provisionar(Request $request)
    {
        // 1. Recibís los datos del pago exitoso
        $serverId = $request->transaction_id; // ej: 'TEST-002'
        $assignedPort = 27351; // El puerto que ya sacaste de tu lógica

        // 2. CREACIÓN DEL DIRECTORIO FÍSICO (El código que vimos)
        $hostPath = "/home/maxpro/Proservers/volumes/{$serverId}";
        
        if (!File::exists($hostPath)) {
            File::makeDirectory($hostPath, 0777, true, true);
        }

        // 3. ARMADO DEL PAYLOAD PARA DOCKER
        $dockerPayload = [
            'Image' => 'itzg/minecraft-server',
            'Env' => [
                'EULA=TRUE',
            ],
            'HostConfig' => [
                'PortBindings' => [
                    '25565/tcp' => [
                        ['HostPort' => (string) $assignedPort]
                    ]
                ],
                'Binds' => [
                    "{$hostPath}:/data"
                ]
            ]
        ];

        // 4. PETICIÓN A LA API DE DOCKER USANDO Http::post()
        // Suponiendo que la API de Docker en Ubuntu escucha en el puerto 2375
        $dockerApiUrl = env('DOCKER_API_URL', 'http://127.0.0.1:2375');

        // Paso A: Crear el contenedor
        $createResponse = Http::post("{$dockerApiUrl}/containers/create?name={$serverId}", $dockerPayload);

        if ($createResponse->successful()) {
            
            // Paso B: Iniciar el contenedor recién creado
            Http::post("{$dockerApiUrl}/containers/{$serverId}/start");

            // 5. GUARDAR EN AZURE SQL (Esto ya lo tenés encaminado)
            // Server::where('transaction_id', $serverId)->update(['status' => 'active']);

            return response()->json([
                'status' => 'success',
                'message' => 'Contenedor creado, volumen montado y servidor iniciado.',
                'puerto' => $assignedPort
            ]);
            
        } else {
            // Manejo de error si Docker rechaza la creación
            return response()->json([
                'status' => 'error',
                'message' => 'Falló la creación en Docker: ' . $createResponse->body()
            ], 500);
        }
    }
}