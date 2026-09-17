<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\DockerService;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Esta función recibe el aviso de AstroPay cuando alguien paga.
     */
    public function handleAstroPay(Request $request, DockerService $docker)
    {
        // 1. AstroPay nos manda el estado del pago
        $status = $request->input('status'); // Ej: 'APPROVED', 'REJECTED', 'PENDING'
        $transactionId = $request->input('merchant_transaction_id');

        // 2. Si el pago entró joya
        if ($status === 'APPROVED') {
            Log::info("Pago aprobado recibido para transacción: " . $transactionId);

            // Buscamos la compra en la base de datos. Si es el TEST-001 y no existe, lo creamos para que no falle.
            $server = Server::firstOrCreate(
                ['transaction_id' => $transactionId],
                ['user_id' => 1, 'status' => 'pending'] // Asumimos un user_id genérico para el test
            );

            // Generamos datos para el servidor (nombre único y un puerto al azar)
            $nombreContenedor = "proservers_mc_" . uniqid();
            $puertoAleatorio = rand(25000, 29999); 
            
            // Acá a futuro leerías tu base de datos para saber qué plan compró.
            // Para probar ahora, le ponemos 2048 MB (2GB).
            $memoriaMb = 2048;

            // 3. ¡LA MAGIA! Llamamos a nuestro archivo DockerService
            $resultado = $docker->crearServidorMinecraft($nombreContenedor, $memoriaMb, $puertoAleatorio);

            if ($resultado['success']) {
                // 4. Guardamos los datos reales del contenedor en la base de datos
                $server->update([
                    'container_id' => $resultado['container_id'],
                    'port' => $puertoAleatorio,
                    'status' => 'active'
                ]);

                Log::info("¡ÉXITO! Servidor de Minecraft creado y guardado en DB. Puerto asignado: " . $puertoAleatorio);
                // (Acá en el futuro podrías mandarle un email automático al cliente con la IP y el Puerto)
            } else {
                // Si Docker falla, marcamos el error en la base de datos
                $server->update(['status' => 'failed_deployment']);
                Log::error("Error al intentar levantar Docker: " . json_encode($resultado['error']));
            }
        }

        // Siempre le respondemos a AstroPay con un OK (HTTP 200) para que no nos vuelva a mandar la alerta
        return response()->json(['message' => 'Webhook procesado correctamente'], 200);
    }
}