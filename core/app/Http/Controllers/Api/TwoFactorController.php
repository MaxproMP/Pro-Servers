<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa)
    {
    }

    public function status(): JsonResponse
    {
        return response()->json(['enabled' => !empty(Auth::user()->google2fa_secret)]);
    }

    /** Genera un secreto pendiente; solo se activa al confirmar un código válido. */
    public function setup(): JsonResponse
    {
        $cliente = Auth::user();
        if (!empty($cliente->google2fa_secret)) {
            return response()->json(['error' => 'El 2FA ya está activo.'], 409);
        }

        $secret = $this->google2fa->generateSecretKey();
        Cache::put("2fa:pending:{$cliente->id}", $secret, now()->addMinutes(10));

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl('ProServers', $cliente->email, $secret),
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => 'required|digits:6'])['code'];
        $cliente = Auth::user();
        $secret = Cache::get("2fa:pending:{$cliente->id}");

        if (!$secret) {
            return response()->json(['error' => 'La configuración expiró. Vuelve a iniciar el proceso.'], 422);
        }
        if (!$this->google2fa->verifyKey($secret, $code)) {
            return response()->json(['error' => 'Código incorrecto.'], 422);
        }

        $cliente->forceFill(['google2fa_secret' => Crypt::encryptString($secret)])->save();
        Cache::forget("2fa:pending:{$cliente->id}");
        $this->marcarVerificado($cliente->id, (int) $request->attributes->get('auth_time', 0));

        return response()->json(['success' => true]);
    }

    public function verify(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => 'required|digits:6'])['code'];
        $cliente = Auth::user();

        if (empty($cliente->google2fa_secret)) {
            return response()->json(['error' => 'El 2FA no está activo.'], 422);
        }
        if (!$this->codigoValido($cliente->google2fa_secret, $code)) {
            return response()->json(['error' => 'Código incorrecto.'], 422);
        }

        $this->marcarVerificado($cliente->id, (int) $request->attributes->get('auth_time', 0));

        return response()->json(['success' => true]);
    }

    public function disable(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => 'required|digits:6'])['code'];
        $cliente = Auth::user();

        if (empty($cliente->google2fa_secret) || !$this->codigoValido($cliente->google2fa_secret, $code)) {
            return response()->json(['error' => 'Código incorrecto.'], 422);
        }

        $cliente->forceFill(['google2fa_secret' => null])->save();

        return response()->json(['success' => true]);
    }

    private function codigoValido(string $secretCifrado, string $code): bool
    {
        return $this->google2fa->verifyKey(Crypt::decryptString($secretCifrado), $code);
    }

    private function marcarVerificado(int $clienteId, int $authTime): void
    {
        Cache::put("2fa:{$clienteId}:{$authTime}", true, now()->addDays(30));

        $indice = array_slice((array) Cache::get("2fa:index:{$clienteId}", []), -50);
        $indice[] = $authTime;
        Cache::put("2fa:index:{$clienteId}", array_values(array_unique($indice)), now()->addDays(30));
    }

    /** Invalida todas las sesiones verificadas de un cliente (recovery de soporte). */
    public static function olvidarSesiones(int $clienteId): void
    {
        foreach ((array) Cache::get("2fa:index:{$clienteId}", []) as $authTime) {
            Cache::forget("2fa:{$clienteId}:{$authTime}");
        }
        Cache::forget("2fa:index:{$clienteId}");
        Cache::forget("2fa:pending:{$clienteId}");
    }
}
