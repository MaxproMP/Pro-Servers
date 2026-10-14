<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\MensajeTicket;
use App\Models\TicketSoporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportController extends Controller
{
    // ===== Staff (ceo | soporte) =====

    public function adminTickets(): JsonResponse
    {
        $tickets = MensajeTicket::with('ticket.cliente')
            ->whereHas('ticket', fn ($q) => $q->where('estado', 'abierto')->whereHas('cliente'))
            ->orderBy('id')
            ->get()
            ->map(fn (MensajeTicket $m) => [
                'id' => $m->id,
                'uid' => $m->ticket->cliente->firebase_uid,
                'email' => $m->ticket->cliente->email,
                'message' => $m->mensaje,
                'timestamp' => $m->created_at?->toIso8601String(),
                'sender' => $m->remitente,
            ])->values();

        return response()->json(['success' => true, 'tickets' => $tickets]);
    }

    public function adminReply(Request $request): JsonResponse
    {
        $data = $request->validate(['uid' => 'required|string', 'reply' => 'required|string|max:2000']);
        $cliente = Cliente::where('firebase_uid', $data['uid'])->first();

        if (!$cliente) {
            return response()->json(['error' => 'Cliente no encontrado.'], 404);
        }

        $this->ticketAbierto($cliente)->mensajes()->create(['remitente' => 'admin', 'mensaje' => $data['reply']]);

        return response()->json(['success' => true]);
    }

    public function adminClose(Request $request): JsonResponse
    {
        $uid = $request->validate(['uid' => 'required|string'])['uid'];
        $cliente = Cliente::where('firebase_uid', $uid)->first();

        if ($cliente) {
            TicketSoporte::where('cliente_id', $cliente->id)->where('estado', 'abierto')->update(['estado' => 'cerrado']);
        }

        return response()->json(['success' => true]);
    }

    // ===== Cliente =====

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|max:2000']);

        $this->ticketAbierto(Auth::user())->mensajes()->create(['remitente' => 'user', 'mensaje' => $data['message']]);

        return response()->json([
            'success' => true,
            'reply' => 'Mensaje enviado al equipo de soporte. Un administrador te responderá a la brevedad.',
        ]);
    }

    /** Respuestas del staff posteriores al id indicado (el cliente las consulta periódicamente). */
    public function messages(Request $request): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $ticket = TicketSoporte::where('cliente_id', Auth::id())->where('estado', 'abierto')->first();

        $mensajes = $ticket
            ? $ticket->mensajes()->where('remitente', 'admin')->where('id', '>', $after)->orderBy('id')->get(['id', 'mensaje'])
            : collect();

        return response()->json(['success' => true, 'messages' => $mensajes]);
    }

    private function ticketAbierto(Cliente $cliente): TicketSoporte
    {
        return TicketSoporte::firstOrCreate(['cliente_id' => $cliente->id, 'estado' => 'abierto']);
    }
}
