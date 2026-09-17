<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DockerService
{
    /**
     * Se conecta al socket interno de Docker en Ubuntu (/var/run/docker.sock)
     */
    public function crearServidorMinecraft($nombreContenedor, $memoriaMb, $puerto)
    {
        // 1. Configurar la llamada a la API de Docker por el socket (v1.44)
        $url = "http://localhost/v1.44/containers/create?name=" . $nombreContenedor;
        
        $body = [
            "Image" => "itzg/minecraft-server",
            "Env" => [
                "EULA=TRUE",
                "MEMORY=" . $memoriaMb . "M"
            ],
            "HostConfig" => [
                "PortBindings" => [
                    "25565/tcp" => [
                        ["HostPort" => (string)$puerto]
                    ]
                ],
                "Memory" => $memoriaMb * 1024 * 1024, // Docker espera el valor en bytes
            ]
        ];

        try {
            // Usamos curl nativo de Laravel/Guzzle pasándole el ID numérico del socket (10231)
            $response = Http::withOptions([
                'curl' => [
                    10231 => '/var/run/docker.sock'
                ]
            ])->post($url, $body);

            if ($response->successful()) {
                $idContenedor = $response->json('Id');
                
                // 2. Si se creó correctamente, lo iniciamos (También en v1.44 y con 10231)
                Http::withOptions([
                    'curl' => [
                        10231 => '/var/run/docker.sock'
                    ]
                ])->post("http://localhost/v1.44/containers/{$idContenedor}/start");

                return ['success' => true, 'container_id' => $idContenedor];
            }

            return ['success' => false, 'error' => $response->body()];

        } catch (\Exception $e) {
            Log::error("Error conectando a Docker: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}