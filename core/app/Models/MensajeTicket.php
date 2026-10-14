<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensajeTicket extends Model
{
    protected $table = 'mensajes_ticket';

    protected $fillable = ['ticket_id', 'remitente', 'mensaje', 'log_analizado'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketSoporte::class, 'ticket_id');
    }
}
