<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketSoporte extends Model
{
    protected $table = 'tickets_soporte';

    protected $fillable = ['cliente_id', 'estado'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeTicket::class, 'ticket_id');
    }
}
