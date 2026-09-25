<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clientes';

    /**
     * Atributos asignables masivamente.
     */
    protected $fillable = [
        'firebase_uid',
        'email',
        'username',
        'nombre',
        'apellido',
        'phone',
        'discord_id',
        'plan_activo',
        'role',
    ];

    /**
     * Atributos ocultos en respuestas JSON.
     */
    protected $hidden = [
        'firebase_uid',
    ];

    /**
     * Cast de tipos para seguridad en PHP 8.3.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // === RELACIONES ===

    /**
     * Un cliente puede tener muchas suscripciones.
     */
    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class, 'id_cliente');
    }

    /**
     * Suscripción activa actual del cliente.
     */
    public function suscripcionActiva()
    {
        return $this->hasOne(Suscripcion::class, 'id_cliente')
            ->where('estado', 'activa')
            ->latest();
    }
}
