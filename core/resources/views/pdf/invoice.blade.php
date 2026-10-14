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
  .box td.k { width: 32%; color: #6b7280; }
  .total td { padding: 12px 8px; font-size: 14px; font-weight: bold; background: #f3f4f6; }
  .estado { font-weight: bold; }
  .foot { margin-top: 36px; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 8px; }
</style>
</head>
<body>
@php
  $c = $pago->suscripcion?->cliente;
  $f = ($pago->fecha_transaccion ?? $pago->created_at);
  $pasarelas = ['mercadopago' => 'MercadoPago', 'astropay' => 'AstroPay', 'uala' => 'Ualá Bis', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'manual' => 'Registro manual'];
@endphp
<table class="brand"><tr>
  <td><div class="name">PROSERVERS</div><div class="sub">Cloud &amp; Game Hosting Automation</div></td>
  <td class="doc">Comprobante de pago<b>N° {{ str_pad((string) $pago->id, 8, '0', STR_PAD_LEFT) }}</b>{{ $f?->format('d/m/Y H:i') }}</td>
</tr></table>

<h3>Cliente</h3>
<table class="box">
  <tr><td class="k">Usuario</td><td>{{ $c?->username ?? '—' }}</td></tr>
  <tr><td class="k">Correo</td><td>{{ $c?->email ?? '—' }}</td></tr>
  <tr><td class="k">UID</td><td>{{ $c?->firebase_uid ?? '—' }}</td></tr>
</table>

<h3>Detalle</h3>
<table class="box">
  <tr><td class="k">Plan contratado</td><td>{{ strtoupper($pago->suscripcion?->plan?->nombre ?? '—') }}</td></tr>
  <tr><td class="k">Suscripción</td><td>#{{ $pago->suscripcion_id }}</td></tr>
  <tr><td class="k">Pasarela</td><td>{{ $pasarelas[strtolower((string) $pago->pasarela_pago)] ?? $pago->pasarela_pago }}</td></tr>
  <tr><td class="k">Medio de pago</td><td>{{ ucfirst((string) $pago->medio_pago) }}</td></tr>
  <tr><td class="k">Transacción</td><td>{{ $pago->transaccion_id ?? '—' }}</td></tr>
  <tr><td class="k">Estado</td><td class="estado">{{ strtoupper($pago->estado) }}</td></tr>
</table>

<table class="total" style="margin-top:14px;"><tr><td>Importe total</td><td style="text-align:right;">USD {{ number_format((float) $pago->monto, 2, ',', '.') }}</td></tr></table>

<div class="foot">Documento generado por ProServers el {{ now()->format('d/m/Y H:i') }}. Este comprobante respalda el movimiento registrado en el libro contable.</div>
</body>
</html>
