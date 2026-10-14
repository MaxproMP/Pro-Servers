<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function list(): JsonResponse
    {
        $staff = Cliente::whereIn('role', ['admin', 'soporte'])->orderBy('email')->get(['id', 'email', 'role'])
            ->map(fn (Cliente $c) => ['id' => $c->id, 'email' => $c->email, 'role' => $c->role])->values();

        return response()->json(['success' => true, 'staff' => $staff]);
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emailToStaff' => 'required|email',
            'role' => ['sometimes', Rule::in(['admin', 'soporte'])],
        ]);
        $cliente = Cliente::where('email', $data['emailToStaff'])->first();

        if (!$cliente) {
            return response()->json(['error' => 'No existe un usuario con ese correo. Debe haber iniciado sesión al menos una vez.'], 404);
        }
        if ($cliente->role === 'ceo') {
            return response()->json(['error' => 'El CEO no puede cambiar de rol.'], 422);
        }

        $cliente->update(['role' => $data['role'] ?? 'soporte']);

        return response()->json(['success' => true]);
    }

    public function remove(Request $request): JsonResponse
    {
        $email = $request->validate(['emailToRemove' => 'required|email'])['emailToRemove'];
        $cliente = Cliente::where('email', $email)->whereIn('role', ['admin', 'soporte'])->first();

        if (!$cliente) {
            return response()->json(['error' => 'Ese usuario no pertenece al staff.'], 404);
        }

        $cliente->update(['role' => 'usuario']);

        return response()->json(['success' => true]);
    }
}
