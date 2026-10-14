<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Cliente;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FirebaseTokenMiddleware
{
    /** Rutas que pueden usarse con 2FA pendiente (el login informa y verify lo resuelve). */
    private const RUTAS_2FA = ['api/login', 'api/auth/login', 'api/2fa/verify'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No Firebase token provided'], 401);
        }

        try {
            $claims = Firebase::auth()->verifyIdToken($token)->claims();
            $uid = $claims->get('sub');
            $email = $claims->get('email');
            $name = $claims->get('name') ?? 'Usuario';
            $emailVerificado = $claims->get('email_verified') === true;
            $authTime = (int) ($claims->get('auth_time') ?? 0);
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

            // El CEO se identifica por correo verificado por Firebase.
            if ($emailVerificado && strcasecmp($cliente->email, (string) config('services.ceo_email')) === 0 && $cliente->role !== 'ceo') {
                $cliente->update(['role' => 'ceo']);
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

        // Zona de Hielo: cuenta congelada, solo queda abierto el contacto con soporte.
        if ($cliente->estado === 'congelado' && !$request->is('api/support/*')) {
            return response()->json([
                'error' => 'account_frozen',
                'message' => 'Tu cuenta está en Zona de Hielo. Contacta a soporte para reactivarla.',
            ], 403);
        }

        // 2FA: la verificación queda ligada al inicio de sesión de Firebase (auth_time).
        $tiene2fa = !empty($cliente->google2fa_secret);
        $verificado = !$tiene2fa || Cache::has("2fa:{$cliente->id}:{$authTime}");
        $request->attributes->set('auth_time', $authTime);
        $request->attributes->set('requires_2fa', !$verificado);

        if (!$verificado && !$request->is(...self::RUTAS_2FA)) {
            return response()->json(['error' => '2fa_required', 'message' => 'Se requiere el código de verificación en dos pasos.'], 403);
        }

        return $next($request);
    }
}
