<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditoriaReembolso extends Model
{
    protected $table = 'auditoria_reembolsos';

    protected $fillable = [
        'pago_id',
        'cliente_id',
        'motivo_solicitud',
        'monto_devuelto',
        'estado_auditoria',
    ];

    protected $casts = [
        'monto_devuelto' => 'decimal:2',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
