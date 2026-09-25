<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Suscripcion extends Model
{
    use SoftDeletes;

    protected $table = 'suscripciones';

    protected $fillable = [
        'cliente_id',
        'plan_id',
        'estado',
        'fecha_inicio',
        'fecha_vencimiento',
        'ciclo_meses',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_vencimiento' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }
}
