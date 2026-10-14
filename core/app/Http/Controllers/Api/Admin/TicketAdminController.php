<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\MensajeTicket;
use App\Models\TicketSoporte;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TicketAdminController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $estado = $request->query('estado', 'abierto');
        $tickets = TicketSoporte::with(['cliente', 'mensajes' => fn ($q) => $q->orderBy('id')])
            ->whereHas('cliente')
            ->when(in_array($estado, ['abierto', 'cerrado'], true), fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('updated_at')->limit(200)->get()
            ->map(function (TicketSoporte $t) {
                $primero = $t->mensajes->first();
                $ultimo = $t->mensajes->last();

                return [
                    'id' => $t->id,
                    'estado' => $t->estado,
                    'asunto' => $primero ? mb_strimwidth($primero->mensaje, 0, 70, '…') : '(sin mensajes)',
                    'cliente' => ['id' => $t->cliente->id, 'username' => $t->cliente->username, 'email' => $t->cliente->email],
                    'mensajes' => $t->mensajes->count(),
                    'ultimo' => $ultimo ? ['remitente' => $ultimo->remitente, 'mensaje' => mb_strimwidth($ultimo->mensaje, 0, 90, '…'), 'at' => $ultimo->created_at?->toIso8601String()] : null,
                ];
            })->values();

        return response()->json(['success' => true, 'tickets' => $tickets]);
    }

    public function show(int $id): JsonResponse
    {
        $t = TicketSoporte::with(['cliente', 'mensajes' => fn ($q) => $q->orderBy('id')])->findOrFail($id);

        return response()->json([
            'success' => true,
            'ticket' => ['id' => $t->id, 'estado' => $t->estado, 'created_at' => $t->created_at?->toIso8601String()],
            'cliente' => ['id' => $t->cliente->id, 'username' => $t->cliente->username, 'email' => $t->cliente->email, 'firebase_uid' => $t->cliente->firebase_uid, 'has_2fa' => !empty($t->cliente->google2fa_secret), 'server_id' => $t->cliente->suscripcionActiva?->server?->id],
            'mensajes' => $t->mensajes->map(fn (MensajeTicket $m) => [
                'id' => $m->id, 'remitente' => $m->remitente, 'mensaje' => $m->mensaje,
                'log_analizado' => $m->log_analizado, 'at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $texto = $request->validate(['message' => 'required|string|max:2000'])['message'];
        $t = TicketSoporte::where('estado', 'abierto')->findOrFail($id);
        $t->mensajes()->create(['remitente' => 'admin', 'mensaje' => $texto]);
        $t->touch();

        return response()->json(['success' => true]);
    }

    public function close(int $id): JsonResponse
    {
        TicketSoporte::findOrFail($id)->update(['estado' => 'cerrado']);
        $this->auditar('admin.ticket_close', null, ['ticket_id' => $id]);

        return response()->json(['success' => true]);
    }

    /** Mine AI Co-Pilot: analiza el log del servidor del cliente y lo guarda en el hilo (log_analizado). */
    public function diagnose(Request $request, int $id): JsonResponse
    {
        $serverId = $request->validate(['serverId' => 'required|string|max:120'])['serverId'];
        $t = TicketSoporte::where('estado', 'abierto')->findOrFail($id);

        $r = $this->node->post('/api/admin/diagnose', ['serverId' => $serverId], 90);
        if (!$r['ok']) {
            return response()->json($r['data'], $r['status']);
        }

        $a = $r['data']['analysis'] ?? [];
        $pasos = collect((array) ($a['paso_a_paso'] ?? []))->map(fn ($p, $i) => ($i + 1) . '. ' . (is_string($p) ? $p : json_encode($p)))->implode("\n");
        $texto = trim(($a['mensaje'] ?? 'Sin conclusión.') . ($pasos ? "\n\nPasos sugeridos:\n" . $pasos : ''));

        $m = $t->mensajes()->create(['remitente' => 'ai', 'mensaje' => $texto, 'log_analizado' => (string) ($r['data']['logExtract'] ?? '')]);
        $this->auditar('admin.ticket_diagnose', $serverId, ['ticket_id' => $id]);

        return response()->json(['success' => true, 'mensaje_id' => $m->id]);
    }

    public function pdf(int $id): Response
    {
        $t = TicketSoporte::with(['cliente.suscripcionActiva.plan', 'mensajes' => fn ($q) => $q->orderBy('id')])->findOrFail($id);
        $html = view('pdf.ticket', [
            'ticket' => $t,
            'diagnosticos' => $t->mensajes->whereNotNull('log_analizado')->where('remitente', 'ai'),
        ])->render();

        return self::renderPdf($html, "ticket-{$t->id}.pdf");
    }

    public static function renderPdf(string $html, string $nombre): Response
    {
        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $nombre . '"',
        ]);
    }
}
