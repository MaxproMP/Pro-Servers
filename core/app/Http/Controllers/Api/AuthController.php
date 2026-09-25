<?php

namespace App\Http\Controllers\Api; // <-- Importante: Ahora está en la carpeta Api

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cliente; // Usamos nuestro modelo Cliente, no el User genérico

class AuthController extends Controller
{
    // === MÉTODO DE REGISTRO (Sprint 7) ===
    public function register(Request $request)
    {
        // 1. Validamos los datos. 
        // Nota POO: Firebase ya validó la contraseña, acá solo validamos el perfil del objeto Cliente.
        $request->validate([
            'firebase_uid' => 'required|string|unique:clientes',
            'email'        => 'required|string|email|max:255|unique:clientes',
            'username'     => 'required|string|max:255|unique:clientes',
            'nombre'       => 'required|string|max:255',
            'apellido'     => 'nullable|string|max:255',
        ]);

        // 2. POO: Instanciamos un nuevo Cliente en la base de datos
        $cliente = Cliente::create([
            'firebase_uid' => $request->firebase_uid,
            'email'        => $request->email,
            'username'     => $request->username,
            'nombre'       => $request->nombre,
            'apellido'     => $request->apellido,
            // 'plan_activo' y 'role' se asignan automáticamente por defecto gracias a la migración
        ]);

        // 3. Devolvemos el JSON de éxito al Frontend
        return response()->json([
            'status'  => 'success',
            'message' => 'Cliente registrado correctamente en ProServers',
            'cliente' => $cliente
        ], 201);
    }

    // === MÉTODO DE LOGIN (Sprint 7) ===
    public function login(Request $request)
    {
        $firebaseUid = (string) $request->attributes->get('firebase_uid');
        $cliente = Cliente::where('firebase_uid', $firebaseUid)->first();

        // 3. Si no existe, lo rebotamos
        if (!$cliente) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cliente no encontrado en la base de datos. Debe registrarse primero.'
            ], 404);
        }

        // 4. Devolvemos los datos del cliente para que el Frontend arme el panel
        return response()->json([
            'status'  => 'success',
            'message' => 'Login exitoso',
            'cliente' => $cliente
        ], 200);
    }
}