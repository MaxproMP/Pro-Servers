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

        $this->ticketAbierto($cliente)->mensajes()->create(['remitente' => 'admin', 'mensaje' => $data['reply'], 'autor_id' => Auth::id()]);

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

    /** Minutos sin actividad en el Panel Admin tras los que el equipo deja de figurar "En línea". */
    private const EN_LINEA_MIN = 5;

    /**
     * Mensajes del ticket abierto posteriores al id indicado (el cliente los consulta periódicamente).
     * Por defecto solo las respuestas del staff; con ?todos=1, el hilo completo con remitente y hora.
     * "equipo" informa quién atiende (nombre de pila del último operador que respondió) y la presencia real del staff.
     */
    public function messages(Request $request): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $todos = $request->boolean('todos');
        $ticket = TicketSoporte::where('cliente_id', Auth::id())->where('estado', 'abierto')->first();

        $mensajes = $ticket
            ? $ticket->mensajes()->with('autor:id,nombre')->when(! $todos, fn ($q) => $q->where('remitente', 'admin'))
                ->where('id', '>', $after)->orderBy('id')->limit(200)->get()
                ->map(fn (MensajeTicket $m) => [
                    'id' => $m->id,
                    'mensaje' => $m->mensaje,
                    'remitente' => $m->remitente,
                    'autor' => $m->autor ? self::nombreDePila($m->autor->nombre) : null,
                    'fecha' => $m->created_at?->toIso8601String(),
                ])->values()
            : collect();

        $operador = $ticket?->mensajes()->where('remitente', 'admin')->whereNotNull('autor_id')->latest('id')->with('autor:id,nombre')->first()?->autor;
        $ultima = Cliente::whereIn('role', ['ceo', 'admin', 'soporte'])->max('ultima_actividad');

        return response()->json([
            'success' => true,
            'messages' => $mensajes,
            'equipo' => [
                'operador' => $operador ? self::nombreDePila($operador->nombre) : null,
                'en_linea' => $ultima !== null && \Illuminate\Support\Carbon::parse($ultima)->gte(now()->subMinutes(self::EN_LINEA_MIN)),
                'ultima_conexion' => $ultima ? \Illuminate\Support\Carbon::parse($ultima)->toIso8601String() : null,
            ],
        ]);
    }

    /** Solo el nombre de pila: el cliente nunca ve correo, usuario ni apellido del staff. */
    private static function nombreDePila(?string $nombre): ?string
    {
        $n = trim((string) $nombre);

        return $n === '' ? null : mb_substr(explode(' ', $n)[0], 0, 40);
    }

    private function ticketAbierto(Cliente $cliente): TicketSoporte
    {
        return TicketSoporte::firstOrCreate(['cliente_id' => $cliente->id, 'estado' => 'abierto']);
    }
}
