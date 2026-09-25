<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Server extends Model
{
    use HasFactory;

    protected $table = 'servers';

    protected $fillable = [
        'suscripcion_id',
        'transaction_id',
        'container_id',     // El ID real del contenedor Docker
        'port',             // El puerto asignado por Playit.gg o Docker
        'status',           // 'pending', 'active', 'failed_deployment', 'paused'
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }
}
