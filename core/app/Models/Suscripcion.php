<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'trial_ends_at',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_vencimiento' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    /** Activas y, si son de prueba, todavía dentro de los 15 días. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where('estado', 'activa')
            ->where(fn (Builder $w) => $w->whereNull('trial_ends_at')->orWhere('trial_ends_at', '>', now()));
    }

    /** Vigentes que generan ingresos (excluye pruebas gratis). */
    public function scopePagas(Builder $q): Builder
    {
        return $q->where('estado', 'activa')->whereNull('trial_ends_at');
    }

    public function enPrueba(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

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

    public function server(): HasOne
    {
        return $this->hasOne(Server::class, 'suscripcion_id');
    }
}
