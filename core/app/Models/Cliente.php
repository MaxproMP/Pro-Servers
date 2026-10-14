<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'clientes';

    /**
     * Atributos asignables masivamente.
     */
    protected $fillable = [
        'firebase_uid',
        'password',
        'google2fa_secret',
        'email',
        'password',
        'google2fa_secret',
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
        'password',
        'google2fa_secret',
    ];

    /**
     * Cast de tipos para seguridad en PHP 8.3.
     */
    protected $casts = [
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Devuelve un username libre (incluye soft-deleted por el UNIQUE), añadiendo sufijo si hace falta.
     */
    public static function usernameUnico(string $base, ?int $ignorarId = null): string
    {
        $base = $base !== '' ? $base : 'user';
        $candidato = $base;
        $i = 0;
        while (static::withTrashed()
            ->where('username', $candidato)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists()) {
            $candidato = $base . (++$i < 10 ? random_int(100, 999) : bin2hex(random_bytes(3)));
        }

        return $candidato;
    }

    // === RELACIONES ===

    /**
     * Un cliente puede tener muchas suscripciones.
     */
    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class, 'cliente_id');
    }

    /**
     * Suscripción activa actual del cliente.
     */
    public function suscripcionActiva()
    {
        return $this->hasOne(Suscripcion::class, 'cliente_id')
            ->where('estado', 'activa')
            ->latest();
    }
}
