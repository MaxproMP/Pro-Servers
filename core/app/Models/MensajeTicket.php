<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensajeTicket extends Model
{
    protected $table = 'mensajes_ticket';

    protected $fillable = ['ticket_id', 'remitente', 'mensaje', 'log_analizado', 'autor_id'];

    /** Miembro del staff que escribió el mensaje (null en mensajes del cliente o de Mine AI). */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'autor_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketSoporte::class, 'ticket_id');
    }
}
