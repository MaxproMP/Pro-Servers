<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\TwoFactorController;
use App\Mail\RecoveryMail;
use App\Models\AuditEvent;
use App\Models\Cliente;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Models\TicketSoporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Kreait\Laravel\Firebase\Facades\Firebase;

final class ClientController extends AdminController
{
    /** TODOS los clientes de SQL Server con su cadena 3FN, tengan o no servidor o plan. */
    public function index(): JsonResponse
    {
        $clientes = Cliente::withTrashed()->with(['suscripcionActiva.plan', 'suscripcionActiva.server'])->orderBy('id')->get();

        $pagos = DB::table('pagos')->join('suscripciones', 'suscripciones.id', '=', 'pagos.suscripcion_id')
            ->selectRaw('suscripciones.cliente_id as cid, count(*) as n')->groupBy('suscripciones.cliente_id')->pluck('n', 'cid');
        $tickets = TicketSoporte::selectRaw('cliente_id, count(*) as n, sum(case when estado = ? then 1 else 0 end) as abiertos', ['abierto'])
            ->groupBy('cliente_id')->get()->keyBy('cliente_id');
        $passkeys = Schema::hasTable('webauthn_credentials')
            ? DB::table('webauthn_credentials')->where('authenticatable_type', Cliente::class)
                ->selectRaw('authenticatable_id as cid, count(*) as n')->groupBy('authenticatable_id')->pluck('n', 'cid')
            : collect();

        return response()->json([
            'success' => true,
            'clients' => $clientes->map(fn (Cliente $c) => $this->resumen($c, $pagos, $tickets, $passkeys))->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $c = Cliente::withTrashed()->with(['suscripcionActiva.plan', 'suscripcionActiva.server'])->findOrFail($id);
        $pagos = DB::table('pagos')->join('suscripciones', 'suscripciones.id', '=', 'pagos.suscripcion_id')
            ->where('suscripciones.cliente_id', $id)->orderByDesc('pagos.id')
            ->get(['pagos.id', 'pagos.monto', 'pagos.pasarela_pago', 'pagos.medio_pago', 'pagos.estado', 'pagos.created_at']);
        $tickets = TicketSoporte::where('cliente_id', $id)->orderByDesc('id')->get(['id', 'estado', 'created_at']);

        return response()->json([
            'success' => true,
            'client' => $this->resumen(
                $c,
                collect([$id => $pagos->count()]),
                collect([$id => (object) ['n' => $tickets->count(), 'abiertos' => $tickets->where('estado', 'abierto')->count()]]),
                collect([$id => Schema::hasTable('webauthn_credentials')
                    ? DB::table('webauthn_credentials')->where('authenticatable_type', Cliente::class)->where('authenticatable_id', $id)->count()
                    : 0]),
            ),
            'pagos' => $pagos,
            'tickets' => $tickets,
            'suscripciones' => Suscripcion::withTrashed()->with('plan')->where('cliente_id', $id)->orderByDesc('id')->get(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = Cliente::withTrashed()->findOrFail($id);
        $data = $request->validate([
            'username' => ['sometimes', 'string', 'min:3', 'max:60', Rule::unique('clientes', 'username')->ignore($id)],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('clientes', 'email')->ignore($id)],
            'role' => ['sometimes', Rule::in(['admin', 'soporte', 'usuario'])],
        ]);

        if (isset($data['role'])) {
            if (!$this->esCeo()) {
                return response()->json(['error' => 'Solo el CEO puede cambiar roles.'], 403);
            }
            if ($c->role === 'ceo') {
                return response()->json(['error' => 'El rol del CEO no se puede modificar.'], 422);
            }
        }
        if ($c->role === 'ceo' && !$this->esCeo()) {
            return response()->json(['error' => 'Solo el CEO puede editar su propia cuenta.'], 403);
        }

        if (isset($data['email']) && $data['email'] !== $c->email && $c->firebase_uid) {
            try {
                Firebase::auth()->changeUserEmail($c->firebase_uid, $data['email']);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Firebase rechazó el cambio de correo: ' . $e->getMessage()], 422);
            }
        }

        $c->update($data);
        $this->auditar('admin.client_update', null, ['cliente_id' => $id, 'campos' => array_keys($data)]);

        return response()->json(['success' => true]);
    }

    public function setPlan(Request $request, int $id): JsonResponse
    {
        $planId = $request->validate(['plan_id' => 'required|integer|exists:planes,id'])['plan_id'];
        $c = Cliente::findOrFail($id);
        $plan = Plan::findOrFail($planId);

        $sub = DB::transaction(function () use ($c, $plan): Suscripcion {
            $sub = Suscripcion::where('cliente_id', $c->id)->whereIn('estado', ['activa', 'pendiente', 'suspendida'])->latest('id')->first();
            $campos = ['plan_id' => $plan->id, 'estado' => 'activa'];
            if (!$sub) {
                return Suscripcion::create($campos + ['cliente_id' => $c->id, 'ciclo_meses' => 1, 'fecha_inicio' => now(), 'fecha_vencimiento' => now()->addMonth()]);
            }
            $sub->update($campos + ['fecha_inicio' => $sub->fecha_inicio ?? now(), 'fecha_vencimiento' => $sub->fecha_vencimiento ?? now()->addMonths($sub->ciclo_meses ?: 1)]);

            return $sub;
        });
        $c->update(['plan_activo' => $plan->nombre]);

        // Los contenedores existentes pasan a los límites del nuevo plan.
        $nodo = $c->firebase_uid ? $this->node->post('/api/admin/apply-limits', ['uid' => $c->firebase_uid, 'plan' => [
            'nombre' => $plan->nombre, 'memoria_mb' => (int) $plan->memoria_mb,
            'cpu_cores' => (int) $plan->cpu_cores, 'almacenamiento_gb' => (int) $plan->almacenamiento_gb,
        ]]) : ['ok' => true, 'data' => ['aplicados' => 0]];
        $this->auditar('admin.set_plan', null, ['cliente_id' => $id, 'plan' => $plan->nombre, 'contenedores' => $nodo['data']['aplicados'] ?? 0]);

        return response()->json(['success' => true, 'suscripcion_id' => $sub->id, 'contenedores' => $nodo['data']], $nodo['ok'] ? 200 : 207);
    }

    public function compensate(Request $request, int $id): JsonResponse
    {
        $horas = (int) ($request->validate(['horas' => 'nullable|integer|min:1|max:8760'])['horas'] ?? 24);
        $sub = Suscripcion::where('cliente_id', $id)->where('estado', 'activa')->latest('id')->first();

        if (!$sub) {
            return response()->json(['error' => 'El cliente no tiene una suscripción activa.'], 422);
        }

        $base = $sub->fecha_vencimiento && $sub->fecha_vencimiento->isFuture() ? $sub->fecha_vencimiento : now();
        $sub->update(['fecha_vencimiento' => $base->copy()->addHours($horas)]);
        $this->auditar('admin.compensate', null, ['cliente_id' => $id, 'horas' => $horas]);

        return response()->json(['success' => true, 'fecha_vencimiento' => $sub->fresh()->fecha_vencimiento?->toIso8601String()]);
    }

    /** Zona de Hielo: congela o descongela la cuenta y sus contenedores. */
    public function freeze(Request $request, int $id): JsonResponse
    {
        $freeze = $request->validate(['freeze' => 'required|boolean'])['freeze'];
        $c = Cliente::findOrFail($id);

        if ($c->role === 'ceo') {
            return response()->json(['error' => 'No se puede congelar al CEO.'], 422);
        }

        $nodo = $c->firebase_uid ? $this->node->post('/api/admin/freeze', ['uid' => $c->firebase_uid, 'freeze' => (bool) $freeze]) : ['ok' => true, 'data' => ['afectados' => 0]];
        $c->update(['estado' => $freeze ? 'congelado' : 'activo']);
        $this->auditar($freeze ? 'admin.freeze' : 'admin.unfreeze', null, ['cliente_id' => $id, 'nodo' => $nodo['data']]);

        return response()->json(['success' => true, 'estado' => $c->estado, 'contenedores' => $nodo['data']], $nodo['ok'] ? 200 : 207);
    }

    /** Caso Juan: reset de 2FA/passkeys y/o contraseña, con aviso por correo y auditoría. */
    public function recovery(Request $request, int $id): JsonResponse
    {
        $modo = $request->validate(['mode' => ['required', Rule::in(['reset_2fa', 'reset_password', 'full_recovery'])]])['mode'];
        $c = Cliente::findOrFail($id);

        if ($c->role === 'ceo' && !$this->esCeo()) {
            return response()->json(['error' => 'Solo el CEO puede recuperar su propia cuenta.'], 403);
        }

        $resultado = ['2fa_reseteado' => false, 'passkeys_eliminadas' => 0, 'enlace_generado' => false, 'correo_enviado' => false];

        if (in_array($modo, ['reset_2fa', 'full_recovery'], true)) {
            $c->forceFill(['google2fa_secret' => null])->save();
            TwoFactorController::olvidarSesiones($c->id);
            if (Schema::hasTable('webauthn_credentials')) {
                $resultado['passkeys_eliminadas'] = DB::table('webauthn_credentials')
                    ->where('authenticatable_type', Cliente::class)->where('authenticatable_id', $c->id)->delete();
            }
            $resultado['2fa_reseteado'] = true;
        }

        $enlace = null;
        if (in_array($modo, ['reset_password', 'full_recovery'], true)) {
            try {
                $enlace = Firebase::auth()->generatePasswordResetLink($c->email);
                $resultado['enlace_generado'] = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            Mail::to($c->email)->send(new RecoveryMail($c->nombre ?: $c->username, $modo, rtrim((string) config('services.public_url'), '/') . '/index.html', $enlace));
            $resultado['correo_enviado'] = true;
        } catch (\Throwable $e) {
            report($e);
        }

        $this->auditar('admin.recovery.' . $modo, null, ['cliente_id' => $id] + $resultado);

        $exito = ($modo === 'reset_2fa' || $resultado['enlace_generado']) && $resultado['correo_enviado'];

        return response()->json(['success' => $exito, 'mode' => $modo] + $resultado, $exito ? 200 : 207);
    }

    /**
     * Con pagos: Soft Delete (ON DELETE RESTRICT preserva la contabilidad). Sin pagos y con ?hard=1,
     * solo el CEO borra físicamente en SQL Server, MongoDB y Firebase.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $c = Cliente::withTrashed()->findOrFail($id);

        if ($c->role === 'ceo') {
            return response()->json(['error' => 'No se puede eliminar al CEO.'], 422);
        }

        $subIds = Suscripcion::withTrashed()->where('cliente_id', $id)->pluck('id');
        $tienePagos = DB::table('pagos')->whereIn('suscripcion_id', $subIds)->exists();
        $fisico = $request->boolean('hard') && !$tienePagos;

        if ($request->boolean('hard') && !$this->esCeo()) {
            return response()->json(['error' => 'Solo el CEO puede realizar un borrado físico.'], 403);
        }

        // Detener y bloquear la infraestructura antes de eliminar.
        if ($c->firebase_uid) {
            $this->node->post('/api/admin/freeze', ['uid' => $c->firebase_uid, 'freeze' => true]);
        }

        if (!$fisico) {
            DB::transaction(function () use ($c): void {
                Suscripcion::where('cliente_id', $c->id)->each(fn (Suscripcion $s) => $s->delete());
                $c->update(['estado' => 'congelado']);
                $c->delete();
            });
            $this->auditar('admin.client_soft_delete', null, ['cliente_id' => $id, 'motivo_soft' => $tienePagos ? 'tiene historial de pagos' : 'solicitado']);

            return response()->json(['success' => true, 'tipo' => 'soft', 'motivo' => $tienePagos ? 'El cliente tiene historial de pagos: se conservó por integridad contable.' : 'Eliminación lógica.']);
        }

        $uid = $c->firebase_uid;
        DB::transaction(function () use ($c, $subIds): void {
            TicketSoporte::where('cliente_id', $c->id)->delete();
            \App\Models\Server::whereIn('suscripcion_id', $subIds)->delete();
            Suscripcion::withTrashed()->where('cliente_id', $c->id)->forceDelete();
            if (Schema::hasTable('webauthn_credentials')) {
                DB::table('webauthn_credentials')->where('authenticatable_type', Cliente::class)->where('authenticatable_id', $c->id)->delete();
            }
            $c->forceDelete();
        });

        $firebase = 'omitido';
        if ($uid) {
            try {
                Firebase::auth()->deleteUser($uid);
                $firebase = 'eliminado';
            } catch (\Throwable $e) {
                $firebase = 'error: ' . $e->getMessage();
            }
        }

        $mongo = 0;
        try {
            $mongo = AuditEvent::whereIn('actor_id', array_filter([$uid, (string) $id]))->delete();
        } catch (\Throwable $e) {
            report($e);
        }

        $this->auditar('admin.client_hard_delete', null, ['cliente_id' => $id, 'email' => $c->email, 'firebase' => $firebase, 'eventos_mongo' => $mongo]);

        return response()->json(['success' => true, 'tipo' => 'fisico', 'firebase' => $firebase, 'eventos_mongo' => $mongo]);
    }

    private function resumen(Cliente $c, $pagos, $tickets, $passkeys): array
    {
        $sub = $c->suscripcionActiva;

        return [
            'id' => $c->id,
            'username' => $c->username,
            'email' => $c->email,
            'firebase_uid' => $c->firebase_uid,
            'rol' => $c->role,
            'estado' => $c->trashed() ? 'eliminado' : $c->estado,
            'created_at' => $c->created_at?->toIso8601String(),
            'plan' => $sub?->plan ? ['id' => $sub->plan->id, 'nombre' => $sub->plan->nombre] : null,
            'suscripcion' => $sub ? ['id' => $sub->id, 'estado' => $sub->estado, 'fecha_vencimiento' => $sub->fecha_vencimiento?->toIso8601String()] : null,
            'server' => $sub?->server ? ['id' => $sub->server->id, 'status' => $sub->server->status, 'port' => $sub->server->port] : null,
            'pagos_count' => (int) ($pagos[$c->id] ?? 0),
            'tickets_count' => (int) ($tickets[$c->id]->n ?? 0),
            'tickets_abiertos' => (int) ($tickets[$c->id]->abiertos ?? 0),
            'has_2fa' => !empty($c->google2fa_secret),
            'has_webauthn' => (int) ($passkeys[$c->id] ?? 0) > 0,
        ];
    }
}
