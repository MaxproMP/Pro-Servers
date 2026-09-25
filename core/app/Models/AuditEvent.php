<?php

declare(strict_types=1);

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

final class AuditEvent extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'audit_events';

    protected $fillable = [
        'event',
        'actor_type',
        'actor_id',
        'server_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
