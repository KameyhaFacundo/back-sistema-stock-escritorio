@props(['logoUrl'])
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#eef0f4; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef0f4;">
    <tr>
      <td align="center" style="padding:48px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(16,24,40,0.10);">
          {{-- Barra de acento con el color de la marca --}}
          <tr>
            <td style="height:4px; line-height:4px; font-size:0; background-color:#5c6ef8;">&nbsp;</td>
          </tr>
          <tr>
            <td style="padding:32px 40px 4px 40px; text-align:center;">
              <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" style="height:34px; width:auto;">
            </td>
          </tr>
          <tr>
            <td style="padding:28px 40px 8px 40px;">
              {{ $slot }}
            </td>
          </tr>
          <tr>
            <td style="padding:28px 40px 32px 40px; text-align:center; border-top:1px solid #eef0f4;">
              <p style="margin:0; color:#98a2b3; font-size:12px; line-height:1.6;">
                &copy; {{ date('Y') }} {{ config('app.name') }} &middot; Todos los derechos reservados
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
