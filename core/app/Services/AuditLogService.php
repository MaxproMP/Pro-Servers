<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;

final class AuditLogService
{
    public function record(
        string $event,
        string $actorType,
        ?string $actorId = null,
        ?string $serverId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'server_id' => $serverId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
