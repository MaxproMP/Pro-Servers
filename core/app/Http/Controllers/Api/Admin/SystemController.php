<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AuditEvent;
use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Suscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SystemController extends AdminController
{
    private const FIXTURES_ACTOR = ['validation', 'refund-smoke-test'];

    /**
     * Elimina de MongoDB los eventos huérfanos: de usuarios inexistentes, de servidores que ya no existen
     * o fixtures de pruebas. Por defecto es un simulacro; con apply=true borra.
     */
    public function syncMongo(Request $request): JsonResponse
    {
        $apply = $request->boolean('apply');

        $r = $this->node->get('/api/admin/all-servers');
        if (!$r['ok']) {
            return response()->json(['error' => 'No se pudo consultar el demonio; abortado para no borrar de más.'], 502);
        }
        $servidores = collect($r['data']['servers'] ?? [])->pluck('id')->all();
        $uids = Cliente::withTrashed()->pluck('firebase_uid')->filter()->all();
        $ids = Cliente::withTrashed()->pluck('id')->map(fn ($i) => (string) $i)->all();

        $candidatos = [];
        try {
            foreach (AuditEvent::all() as $e) {
                $motivo = null;
                if (in_array($e->actor_id, self::FIXTURES_ACTOR, true)) {
                    $motivo = 'evento de prueba (fixture)';
                } elseif (in_array($e->actor_type, ['user', 'client'], true) && !in_array($e->actor_id, [...$uids, ...$ids], true)) {
                    $motivo = 'cliente inexistente en SQL Server';
                } elseif ($e->server_id && !in_array($e->server_id, $servidores, true)) {
                    $motivo = 'servidor inexistente en Docker';
                }
                if ($motivo) {
                    $candidatos[] = ['id' => (string) $e->getKey(), 'event' => $e->event, 'motivo' => $motivo];
                }
            }

            if ($apply && $candidatos) {
                AuditEvent::whereIn('_id', array_column($candidatos, 'id'))->delete();
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'MongoDB no disponible.'], 502);
        }

        $this->auditar('admin.sync_mongo', null, ['apply' => $apply, 'eliminados' => $apply ? count($candidatos) : 0]);

        return response()->json([
            'success' => true,
            'dry_run' => !$apply,
            'candidatos' => $candidatos,
            'eliminados' => $apply ? count($candidatos) : 0,
        ]);
    }

    /** Clientes sembrados por pruebas (Faker / admin falso) sin pagos. Soft delete reversible. */
    public function purgeSeed(Request $request): JsonResponse
    {
        $apply = $request->boolean('apply');
        $semillas = Cliente::query()
            ->where(fn ($q) => $q->where('email', 'like', '%@example.com')
                ->orWhere('email', 'like', '%@example.net')
                ->orWhere('email', 'like', '%@example.org')
                ->orWhere('email', 'admin@proservers.com'))
            ->get()
            ->filter(fn (Cliente $c) => !Pago::whereIn('suscripcion_id', Suscripcion::withTrashed()->where('cliente_id', $c->id)->pluck('id'))->exists());

        if ($apply) {
            DB::transaction(function () use ($semillas): void {
                foreach ($semillas as $c) {
                    Suscripcion::where('cliente_id', $c->id)->each(fn (Suscripcion $s) => $s->delete());
                    $c->delete();
                }
            });
            $this->auditar('admin.purge_seed', null, ['clientes' => $semillas->pluck('email')->all()]);
        }

        return response()->json([
            'success' => true,
            'dry_run' => !$apply,
            'clientes' => $semillas->map(fn (Cliente $c) => ['id' => $c->id, 'email' => $c->email, 'username' => $c->username])->values(),
        ]);
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
