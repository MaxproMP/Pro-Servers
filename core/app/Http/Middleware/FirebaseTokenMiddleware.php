<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Cliente;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class FirebaseTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No Firebase token provided'], 401);
        }

        try {
            $verifiedIdToken = Firebase::auth()->verifyIdToken($token);
            $uid = $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name') ?? 'Usuario';
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid Firebase token: ' . $e->getMessage()], 401);
        }

        try {
            $partes = explode(' ', (string) $name);
            $email = $email ?? $uid . '@proservers.com';

            $cliente = Cliente::where('firebase_uid', $uid)->first();

            if (!$cliente) {
                $cliente = Cliente::create([
                    'firebase_uid' => $uid,
                    'email' => $email,
                    'nombre' => $partes[0] ?: 'Usuario',
                    'apellido' => $partes[1] ?? '',
                    'username' => Cliente::usernameUnico(explode('@', $email)[0]),
                    'password' => bcrypt(Str::random(24)),
                    'role' => 'usuario',
                    'plan_activo' => 'ninguno',
                ]);
            }

            Auth::login($cliente);
        } catch (\Throwable $e) {
            // Carrera entre peticiones simultáneas: otro request ya creó el cliente.
            $cliente = Cliente::where('firebase_uid', $uid)->first();
            if (!$cliente) {
                report($e);
                return response()->json(['error' => 'Error de base de datos al autenticar al cliente.'], 500);
            }
            Auth::login($cliente);
        }

        return $next($request);
    }
}
