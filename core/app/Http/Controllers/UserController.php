<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function registrar(Request $request)
    {
        // 1. Validamos que manden los datos y que el username/email no existan ya
        $validator = Validator::make($request->all(), [
            'firebase_uid' => 'required|string|unique:users',
            'email'        => 'required|email|unique:users',
            'username'     => 'required|string|unique:users',
            'nombre'       => 'required|string',
            'apellido'     => 'required|string',
            'phone'        => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // 2. Guardamos el cliente directamente en Microsoft SQL Server (Azure)
        $user = User::create([
            'firebase_uid' => $request->firebase_uid,
            'email'        => $request->email,
            'username'     => strtolower($request->username), // Guardamos siempre en minúsculas
            'nombre'       => $request->nombre,
            'apellido'     => $request->apellido,
            'phone'        => $request->phone,
            'role'         => 'cliente', // Por defecto todos son clientes comunes
            'plan_activo'  => 'ninguno'  // Entran sin plan hasta que paguen
        ]);

        return response()->json([
            'mensaje' => '¡Usuario registrado en el Cerebro con éxito!',
            'usuario' => $user
        ], 201);
    }

    public function perfil($firebase_uid)
    {
        // Buscamos al usuario por su ID de Firebase
        $user = User::where('firebase_uid', $firebase_uid)->first();

        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        return response()->json($user, 200);
    }
}