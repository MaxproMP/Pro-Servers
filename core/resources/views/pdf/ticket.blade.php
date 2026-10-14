<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 36px 40px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111827; }
  table { width: 100%; border-collapse: collapse; }
  .brand { background: #0b0d13; color: #ffffff; padding: 18px 22px; }
  .brand .name { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
  .brand .sub { font-size: 9px; color: #9ca3af; margin-top: 3px; }
  .brand .doc { text-align: right; font-size: 10px; color: #d1d5db; }
  .brand .doc b { display: block; font-size: 15px; color: #ffffff; }
  h3 { font-size: 11px; margin: 22px 0 6px; color: #374151; text-transform: uppercase; }
  .box td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  .box td.k { width: 28%; color: #6b7280; }
  .ai { background: #f5f3ff; border: 1px solid #c4b5fd; padding: 10px 12px; margin-bottom: 8px; }
  .ai pre { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; background: #111827; color: #e5e7eb; padding: 8px; white-space: pre-wrap; word-wrap: break-word; }
  .msg { margin-bottom: 8px; padding: 8px 10px; border: 1px solid #e5e7eb; }
  .msg.admin { background: #ecfeff; border-color: #a5f3fc; }
  .msg.ai { background: #f5f3ff; border-color: #c4b5fd; }
  .meta { font-size: 9px; color: #6b7280; margin-bottom: 3px; }
  .foot { margin-top: 28px; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 8px; }
</style>
</head>
<body>
@php $c = $ticket->cliente; @endphp
<table class="brand"><tr>
  <td><div class="name">PROSERVERS</div><div class="sub">Reporte oficial de soporte</div></td>
  <td class="doc">Ticket<b>#{{ str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT) }}</b>{{ $ticket->created_at?->format('d/m/Y H:i') }}</td>
</tr></table>

<h3>Datos del ticket</h3>
<table class="box">
  <tr><td class="k">Asunto</td><td>{{ \Illuminate\Support\Str::limit($ticket->mensajes->first()?->mensaje ?? '(sin mensajes)', 120) }}</td></tr>
  <tr><td class="k">Estado</td><td><b>{{ strtoupper($ticket->estado) }}</b></td></tr>
  <tr><td class="k">Cliente</td><td>{{ $c?->username }} &lt;{{ $c?->email }}&gt;</td></tr>
  <tr><td class="k">UID</td><td>{{ $c?->firebase_uid ?? '—' }}</td></tr>
  <tr><td class="k">Plan</td><td>{{ strtoupper($c?->suscripcionActiva?->plan?->nombre ?? 'sin plan') }}</td></tr>
  <tr><td class="k">Mensajes</td><td>{{ $ticket->mensajes->count() }}</td></tr>
</table>

@if ($diagnosticos->isNotEmpty())
<h3>Diagnóstico técnico de Mine AI</h3>
@foreach ($diagnosticos as $d)
  <div class="ai">
    <div class="meta">{{ $d->created_at?->format('d/m/Y H:i') }}</div>
    <div>{!! nl2br(e($d->mensaje)) !!}</div>
    <h3 style="margin:10px 0 4px;">Extracto de log analizado</h3>
    <pre>{{ \Illuminate\Support\Str::limit($d->log_analizado, 2500) }}</pre>
  </div>
@endforeach
@endif

<h3>Historial de mensajes</h3>
@foreach ($ticket->mensajes as $m)
  @php $quien = ['user' => 'Cliente', 'admin' => 'Staff ProServers', 'ai' => 'Mine AI'][$m->remitente] ?? $m->remitente; @endphp
  <div class="msg {{ $m->remitente }}">
    <div class="meta"><b>{{ $quien }}</b> — {{ $m->created_at?->format('d/m/Y H:i') }}</div>
    <div>{!! nl2br(e($m->mensaje)) !!}</div>
  </div>
@endforeach

<div class="foot">Documento generado por ProServers el {{ now()->format('d/m/Y H:i') }}.</div>
</body>
</html>
