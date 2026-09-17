<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    // Le indicamos a Laravel exactamente qué tabla usar
    protected $table = 'servers';

    // Autorizamos los campos que podemos llenar de forma automática desde el Webhook
    protected $fillable = [
        'user_id', 
        'transaction_id', 
        'container_id', 
        'port', 
        'status',
        // Si tenés otros campos en esa tabla (ej: 'name', 'plan_id'), agregalos acá
    ];
}