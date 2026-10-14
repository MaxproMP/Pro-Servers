<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function list(): JsonResponse
    {
        $staff = Cliente::where('role', 'soporte')->orderBy('email')->get(['email'])
            ->map(fn (Cliente $c) => ['email' => $c->email])->values();

        return response()->json(['success' => true, 'staff' => $staff]);
    }

    public function add(Request $request): JsonResponse
    {
        $email = $request->validate(['emailToStaff' => 'required|email'])['emailToStaff'];
        $cliente = Cliente::where('email', $email)->first();

        if (!$cliente) {
            return response()->json(['error' => 'No existe un usuario con ese correo. Debe haber iniciado sesión al menos una vez.'], 404);
        }
        if ($cliente->role === 'ceo') {
            return response()->json(['error' => 'El CEO no puede cambiar de rol.'], 422);
        }

        $cliente->update(['role' => 'soporte']);

        return response()->json(['success' => true]);
    }

    public function remove(Request $request): JsonResponse
    {
        $email = $request->validate(['emailToRemove' => 'required|email'])['emailToRemove'];
        $cliente = Cliente::where('email', $email)->where('role', 'soporte')->first();

        if (!$cliente) {
            return response()->json(['error' => 'Ese usuario no pertenece al staff de soporte.'], 404);
        }

        $cliente->update(['role' => 'usuario']);

        return response()->json(['success' => true]);
    }
}
