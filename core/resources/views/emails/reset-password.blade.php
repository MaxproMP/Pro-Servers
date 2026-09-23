@component('mail::message')
# ¡Hola, {{ $user->name ?? 'Usuario' }}! 👋

Por favor, confirma la solicitud para restablecer la contraseña de tu cuenta de **Professional Servers** (servidores de Minecraft y CurseForge modpacks).

<div class="table">
  <table>
    <thead>
      <tr>
        <th colspan="2" style="text-align: center;">🔒 SEGURIDAD DE LA CUENTA</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>TIPO DE SOLICITUD</strong></td>
        <td>Recuperación de Cuenta / Cambio de Contraseña</td>
      </tr>
      <tr>
        <td><strong>FECHA DE SOLICITUD</strong></td>
        <td>{{ \Carbon\Carbon::now()->translatedFormat('d de F, Y') }}</td>
      </tr>
      <tr>
        <td><strong>HORA DE SOLICITUD</strong></td>
        <td>{{ \Carbon\Carbon::now()->format('H:i') }} UTC</td>
      </tr>
      <tr>
        <td><strong>IP DE ORIGEN</strong></td>
        <td>{{ request()->ip() ?? '186.130.x.x (mascarada)' }}</td>
      </tr>
      <tr>
        <td><strong>UBICACIÓN ESTIMADA</strong></td>
        <td>Leandro N. Alem, Argentina</td>
      </tr>
      <tr>
        <td><strong>DISPOSITIVO</strong></td>
        <td>{{ \Illuminate\Support\Str::limit(request()->header('User-Agent') ?? 'Chrome en Windows 10', 35) }}</td>
      </tr>
    </tbody>
  </table>
</div>

@component('mail::button', ['url' => $url, 'color' => 'blue'])
[RECOBRAR CONTRASEÑA]
@endcomponent

<p style="text-align: center; font-size: 12px; color: #71717a; margin-top: 15px;">
  Este enlace caducará en 15 minutos.<br>
  Si no has sido tú, haz clic <a href="#" style="color: #4ade80;">aquí</a> para reportar actividad sospechosa.
</p>

<p style="text-align: center; font-size: 12px; color: #71717a;">
  Si tienes dudas sobre la seguridad de tu cuenta, abre un ticket en Discord o contáctanos a soporte.professional.servers@gmail.com.
</p>

<div style="text-align: center; margin-top: 20px; font-size: 14px; color: #e4e4e7;">
  Atentamente,<br>
  El equipo de Professional Servers<br>
  🌐 professional-servers.com.ar
</div>
@endcomponent