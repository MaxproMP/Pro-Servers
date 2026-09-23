@component('mail::message')
# ¡Hola, {{ $user->name ?? 'Usuario' }}! 👋

Tu pago ha sido procesado con éxito. Gracias por confiar en **Professional Servers** para alojar tu comunidad de Minecraft y tus modpacks favoritos de CurseForge.

<div class="table">
  <table>
    <thead>
      <tr>
        <th colspan="3" style="text-align: center;">🧾 FACTURA DE COMPRA</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td colspan="2"><strong>Factura Nº:</strong></td>
        <td style="text-align: right;">#PS-{{ $invoiceId ?? rand(10000, 99999) }}</td>
      </tr>
      <tr>
        <td colspan="2"><strong>Fecha de cobro:</strong></td>
        <td style="text-align: right;">{{ \Carbon\Carbon::now()->translatedFormat('d de F, Y') }}</td>
      </tr>
      <tr>
        <td colspan="2"><strong>Método de pago:</strong></td>
        <td style="text-align: right;">{{ $paymentMethod ?? 'Tarjeta / Pasarela' }}</td>
      </tr>
      <tr style="background-color: #18181b; color: #4ade80;">
        <td style="font-size: 13px; font-weight: bold; text-transform: uppercase;">DESCRIPCIÓN</td>
        <td style="font-size: 13px; font-weight: bold; text-transform: uppercase; text-align: center;">CANTIDAD</td>
        <td style="font-size: 13px; font-weight: bold; text-transform: uppercase; text-align: right;">MONTO</td>
      </tr>
      <tr>
        <td>
          {{ $planName ?? 'Plan Hierro (Starter)' }} - Alojamiento Premium para Minecraft | 1 Mes<br><br>
          📦 <strong>Tu Plan {{ str_replace('Plan ', '', $planName ?? 'Hierro') }} incluye (Activo):</strong><br>
          • 🖥️ Hasta {{ $planServers ?? 2 }} Servidores<br>
          • 👥 {{ $planSlots ?? 20 }} Slots (Límite Ampliado)<br>
          • ⚡ {{ $planRam ?? 12 }} GB RAM (Mejora de Rendimiento)<br>
          • 🛠️ Soporte completo para mods (CurseForge/Modrinth, enhanced details)
        </td>
        <td style="text-align: center; vertical-align: top;">1 Mes</td>
        <td style="text-align: right; vertical-align: top;">${{ number_format($amount ?? 9.00, 2) }} USD</td>
      </tr>
      <tr style="border-top: 2px solid #4ade80;">
        <td colspan="2"><strong>TOTAL PAGADO |</strong></td>
        <td style="text-align: right; font-weight: bold;">${{ number_format($amount ?? 9.00, 2) }} USD</td>
      </tr>
      <tr>
        <td colspan="3" style="font-size: 12px;">💳 Facturado a la tarjeta terminada en: **** {{ $cardLastFour ?? '----' }}</td>
      </tr>
    </tbody>
  </table>
</div>

@component('mail::button', ['url' => 'http://20.83.164.194/dashboard', 'color' => 'blue'])
👉 [IR AL PANEL DE CONTROL]
@endcomponent

<p style="text-align: center; font-size: 13px;">
  ¿Tienes algún problema con tu servidor o tus mods? No dudes en abrir un ticket en nuestro servidor de Discord o responder a este correo (<a href="mailto:soporte.professional.servers@gmail.com" style="color: #4ade80;">soporte.professional.servers@gmail.com</a>).<br>
  ¡Nuestro equipo está listo para ayudarte!
</p>

<p style="text-align: center; margin-top: 15px;">
  <span style="font-size: 24px;">⛏️</span> ¡A disfrutar de tu servidor! <span style="font-size: 24px;">🗡️</span>
</p>

<div style="text-align: center; margin-top: 20px; font-size: 14px; color: #e4e4e7;">
  Atentamente,<br>
  El equipo de Professional Servers<br>
  🌐 professional-servers.com.ar
</div>

<p style="text-align: center; font-size: 10px; color: #71717a; margin-top: 30px;">
  Este es un comprobante de pago generado automáticamente. Guárdalo para tus registros.
</p>
@endcomponent
