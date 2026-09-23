<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    protected $table = 'servers';

    protected $fillable = [
        'user_id', 
        'firebase_uid',     // Vinculación con Firebase Auth
        'plan_nombre',      // Qué plan está corriendo (Redstone, Diamante, etc.)
        'transaction_id', 
        'container_id',     // El ID real del contenedor Docker
        'port',             // El puerto asignado por Playit.gg o Docker
        'status',           // 'pending', 'active', 'failed_deployment', 'paused'
    ];
}