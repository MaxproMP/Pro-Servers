<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SystemController extends AdminController
{
    public function __construct(\App\Services\NodeClient $node, \App\Services\AuditLogService $audit, private \App\Services\TestDataPurger $purger)
    {
        parent::__construct($node, $audit);
    }

    /**
     * Elimina de MongoDB los eventos huérfanos: de clientes inexistentes, de servidores que ya no existen
     * o fixtures de pruebas. Por defecto es un simulacro; con apply=true borra.
     */
    public function syncMongo(Request $request): JsonResponse
    {
        $apply = $request->boolean('apply');

        $r = $this->node->get('/api/admin/all-servers');
        if (!$r['ok']) {
            return response()->json(['error' => 'No se pudo consultar el demonio; abortado para no borrar de más.'], 502);
        }

        try {
            $candidatos = $this->purger->purgarMongo($apply, collect($r['data']['servers'] ?? [])->pluck('id')->all());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'MongoDB no disponible.'], 502);
        }

        $this->auditar('admin.sync_mongo', null, ['apply' => $apply, 'eliminados' => $apply ? count($candidatos) : 0]);

        return response()->json(['success' => true, 'dry_run' => !$apply, 'candidatos' => $candidatos, 'eliminados' => $apply ? count($candidatos) : 0]);
    }

    /** Cuentas de prueba (Faker / admin sembrado) sin pagos: borrado físico en SQL Server y de sus relaciones. */
    public function purgeSeed(Request $request): JsonResponse
    {
        $apply = $request->boolean('apply');
        $r = $this->purger->purgarSql($apply);

        if ($apply) {
            // Los eventos de Mongo de esas cuentas dejan de tener sentido.
            try {
                $nodo = $this->node->get('/api/admin/all-servers');
                if ($nodo['ok']) {
                    $this->purger->purgarMongo(true, collect($nodo['data']['servers'] ?? [])->pluck('id')->all(), array_map(fn ($c) => (string) $c['id'], $r['clientes']));
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $this->auditar('admin.purge_seed', null, ['clientes' => array_column($r['clientes'], 'email')]);
        }

        return response()->json(['success' => true, 'dry_run' => !$apply, 'clientes' => $r['clientes']]);
    }

    public function serverAction(Request $request): JsonResponse
    {
        $data = $request->validate(['serverId' => 'required|string|max:120', 'action' => 'required|in:start,stop,restart,delete']);

        if ($this->actor()->role === 'soporte' && !in_array($data['action'], ['start', 'restart'], true)) {
            return response()->json(['error' => 'El rol soporte solo puede iniciar o reiniciar nodos.'], 403);
        }

        $r = $this->node->post('/api/admin/server-action', $data);
        $this->auditar('admin.server_action', $data['serverId'], ['action' => $data['action'], 'ok' => $r['ok']]);

        return response()->json($r['data'], $r['status']);
    }

    public function prune(): JsonResponse
    {
        $r = $this->node->post('/api/admin/system-prune', [], 120);
        $this->auditar('admin.system_prune', null, $r['data']);

        return response()->json($r['data'], $r['status']);
    }

    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|max:500', 'level' => 'in:info,warn,danger']);
        $r = $this->node->post('/api/admin/global-broadcast', $data + ['author' => $this->actor()->username]);
        $this->auditar('admin.broadcast', null, ['message' => $data['message'], 'discord' => $r['data']['discord'] ?? null]);

        return response()->json($r['data'], $r['status']);
    }
}
