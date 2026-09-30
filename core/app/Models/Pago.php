<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'suscripcion_id',
        'monto',
        'pasarela_pago',
        'medio_pago',
        'transaccion_id',
        'estado',
        'fecha_transaccion',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_transaccion' => 'datetime',
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function auditoriasReembolso(): HasMany
    {
        return $this->hasMany(AuditoriaReembolso::class);
    }
}
