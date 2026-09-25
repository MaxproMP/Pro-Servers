<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditController extends Controller
{
    public function store(Request $request, AuditLogService $auditLog): JsonResponse
    {
        $secret = (string) $request->header('x-daemon-secret');
        $configuredSecret = (string) config('services.daemon.secret');

        if ($configuredSecret === '' || ! hash_equals($configuredSecret, $secret)) {
            return response()->json(['error' => 'Acceso denegado.'], 403);
        }

        $data = $request->validate([
            'event' => ['required', 'string', 'max:120'],
            'actor_type' => ['required', 'string', 'max:40'],
            'actor_id' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:120'],
            'payload' => ['nullable', 'array'],
        ]);

        $event = $auditLog->record(
            $data['event'],
            $data['actor_type'],
            $data['actor_id'] ?? null,
            $data['server_id'] ?? null,
            $data['payload'] ?? [],
        );

        return response()->json(['success' => true, 'id' => (string) $event->getKey()], 201);
    }
}
