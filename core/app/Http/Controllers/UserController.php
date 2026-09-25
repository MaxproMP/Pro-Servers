<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function registrar(Request $request)
    {
        // 1. Validamos que manden los datos y que el username/email no existan ya
        $validator = Validator::make($request->all(), [
            'firebase_uid' => 'required|string|unique:clientes',
            'email' => 'required|email|unique:clientes',
            'username' => 'required|string|unique:clientes',
            'nombre' => 'required|string',
            'apellido' => 'required|string',
            'phone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // 2. Guardamos el cliente directamente en Microsoft SQL Server (Azure)
        $cliente = Cliente::create([
            'firebase_uid' => $request->firebase_uid,
            'email' => $request->email,
            'username' => strtolower($request->username), // Guardamos siempre en minúsculas
            'nombre' => $request->nombre,
            'apellido' => $request->apellido,
            'phone' => $request->phone,
            'role' => 'cliente', // Por defecto todos son clientes comunes
            'plan_activo' => 'ninguno',  // Entran sin plan hasta que paguen
        ]);

        return response()->json([
            'mensaje' => '¡Usuario registrado en el Cerebro con éxito!',
            'usuario' => $cliente,
        ], 201);
    }

    public function perfil(string $firebase_uid)
    {
        // Buscamos al usuario por su ID de Firebase
        $cliente = Cliente::where('firebase_uid', $firebase_uid)->first();

        if (! $cliente) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        return response()->json($cliente, 200);
    }
}
