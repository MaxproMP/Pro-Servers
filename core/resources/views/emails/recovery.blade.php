<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#050609;font-family:Arial,Helvetica,sans-serif;color:#e5e7eb;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#050609;padding:32px 12px;">
  <tr><td align="center">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#0b0d13;border:1px solid #1c2030;border-radius:14px;">
      <tr><td style="padding:28px 32px 8px;">
        <div style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:.3px;">ProServers</div>
        <div style="font-size:12px;color:#8b93a7;margin-top:2px;">Soporte técnico</div>
      </td></tr>
      <tr><td style="padding:16px 32px 0;">
        <p style="font-size:16px;color:#ffffff;margin:0 0 12px;">Hola {{ $nombre }},</p>
        @if ($modo === 'reset_2fa')
          <p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Nuestro equipo de soporte <b>desactivó la verificación en dos pasos</b> de tu cuenta, tal como lo solicitaste. Ya puedes iniciar sesión sin código.</p>
        @elseif ($modo === 'reset_password')
          <p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Soporte generó un enlace seguro para que <b>restablezcas tu contraseña</b>.</p>
        @else
          <p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Soporte realizó una <b>recuperación completa</b> de tu acceso: se desactivó la verificación en dos pasos y se generó un enlace para restablecer tu contraseña.</p>
        @endif
        @if ($resetLink)
          <p style="margin:20px 0;"><a href="{{ $resetLink }}" style="background:#8b5cf6;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:8px;display:inline-block;">Restablecer contraseña</a></p>
          <p style="font-size:12px;color:#8b93a7;margin:0 0 14px;">Si el botón no funciona, copia este enlace en tu navegador:<br><span style="word-break:break-all;color:#06b6d4;">{{ $resetLink }}</span></p>
        @endif
        <p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Después de entrar, te recomendamos <b>volver a activar el 2FA</b> desde tu perfil.</p>
        <p style="margin:18px 0;"><a href="{{ $loginUrl }}" style="color:#06b6d4;font-size:14px;">Ir a ProServers</a></p>
      </td></tr>
      <tr><td style="padding:16px 32px 28px;border-top:1px solid #1c2030;">
        <p style="font-size:12px;line-height:1.5;color:#8b93a7;margin:0;">Si tú no pediste este cambio, responde a este correo de inmediato: alguien pudo solicitar el restablecimiento en tu nombre.</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
