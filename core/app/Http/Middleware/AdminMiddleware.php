<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /** Uso: 'admin' (solo ceo) o 'admin:ceo,soporte'. */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $roles = $roles ?: ['ceo'];

        if (!$request->user() || !in_array($request->user()->role, $roles, true)) {
            return response()->json(['error' => 'Acceso denegado: Se requiere rol de administrador'], 403);
        }

        return $next($request);
    }
}
