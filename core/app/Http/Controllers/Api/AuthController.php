<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cliente;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $cliente = Auth::user(); // Obtenido por el middleware FirebaseTokenMiddleware
        
        if (!$cliente) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        try {
            $username = $request->input('username');
            $cliente->update([
                'username' => $username
                    ? Cliente::usernameUnico((string) $username, $cliente->id)
                    : $cliente->username,
                'nombre'   => $request->input('nombre', $cliente->nombre),
                'apellido' => $request->input('apellido', $cliente->apellido),
                'phone'    => $request->input('phone', $cliente->phone),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Error de base de datos al completar el registro.'], 500);
        }

        return response()->json(['message' => 'Registro completado', 'user' => $cliente], 201);
    }

    public function login(Request $request)
    {
        $cliente = Auth::user();
        
        if (!$cliente) {
            return response()->json(['message' => 'Cliente no registrado en la base de datos.'], 404);
        }

        return response()->json([
            'message'      => 'Login exitoso',
            'user'         => $cliente,
            'requires_2fa' => (bool) $request->attributes->get('requires_2fa', false),
        ], 200);
    }
}
