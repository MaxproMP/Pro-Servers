<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PlanAdminController extends AdminController
{
    public const CACHE_CATALOGO = 'catalogo:publico:v2';

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'planes' => Plan::orderBy('precio_mensual')->get()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'precio_mensual' => 'sometimes|numeric|min:0|max:99999',
            'memoria_mb' => 'sometimes|integer|gt:0',
            'cpu_cores' => 'sometimes|integer|gt:0',
            'almacenamiento_gb' => 'sometimes|integer|gt:0',
            'max_jugadores' => 'sometimes|nullable|integer|between:1,1000',
            'visible' => 'sometimes|boolean',
        ]);

        $plan = Plan::findOrFail($id);
        $plan->forceFill($data)->save();
        Cache::forget(self::CACHE_CATALOGO); // planes.html y checkout ven el cambio al instante
        $this->auditar('admin.plan_update', null, ['plan' => $plan->nombre] + $data);

        return response()->json(['success' => true, 'plan' => $plan->fresh()]);
    }

    /** Catálogo público: solo planes visibles. */
    public function publico(): JsonResponse
    {
        $prueba = config('services.trial.planes');
        // Catálogo cacheado 10 min; se invalida al editar un plan desde Overwatch.
        $planes = Cache::remember(self::CACHE_CATALOGO, 600, fn () => Plan::where('visible', true)->orderBy('precio_mensual')
            ->get(['id', 'nombre', 'precio_mensual', 'memoria_mb', 'cpu_cores', 'almacenamiento_gb', 'max_jugadores'])
            ->map(fn (Plan $p) => $p->toArray() + ['prueba' => in_array($p->nombre, $prueba, true)])->all());

        return response()->json(['trial_dias' => (int) config('services.trial.dias'), 'planes' => $planes]);
    }
}
