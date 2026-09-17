<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    // === MÉTODO DE LOGIN (Sprint 7) ===
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::attempt($request->only('email', 'password'))) {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $token = $user->createToken('api_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login exitoso',
                'token' => $token,
                'user' => $user
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Credenciales incorrectas'
        ], 401);
    }

    // === MÉTODO DE REGISTRO (Sprint 7) ===
    public function register(Request $request)
    {
        // 1. Validamos los datos que manda Node.js
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8', // Mínimo 8 caracteres
        ]);

        // 2. Creamos el usuario y encriptamos la contraseña al vuelo
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password), // Encriptación BCRYPT
        ]);

        // 3. Generamos el token de una vez para que inicie sesión directo si queremos
        $token = $user->createToken('api_token')->plainTextToken;

        // 4. Devolvemos el JSON de éxito a Node.js
        return response()->json([
            'status' => 'success',
            'message' => 'Usuario registrado correctamente en el imperio',
            'token' => $token,
            'user' => $user
        ], 201);
    }
}