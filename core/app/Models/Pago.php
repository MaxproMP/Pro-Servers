<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'suscripcion_id', // Relación original
        'firebase_uid',   // Identificador de Firebase
        'monto', 
        'metodo',         // Ej: 'astropay', 'lemon', 'tarjeta'
        'transaccion_id', 
        'estado'
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }
}