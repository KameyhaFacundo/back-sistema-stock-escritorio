{{-- Logo incrustado (Content-ID), no una URL externa — ver reset-password.blade.php --}}
@php $logoCid = $message->embed(
    \Illuminate\Mail\Attachment::fromPath(resource_path('branding/logo.png'))->as('logo.png')->withMime('image/png')
); @endphp
<x-mail-layout :logo-url="$logoCid">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0">
          <tr>
            <td style="width:52px; height:52px; border-radius:14px; background-color:#f4f5ff; text-align:center; vertical-align:middle; font-size:24px; line-height:52px;">
              🎉
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <h1 style="margin:0 0 10px 0; color:#101828; font-size:21px; font-weight:800; letter-spacing:-0.02em; text-align:center;">
    ¡Bienvenido, {{ $nombre }}!
  </h1>
  <p style="margin:0 0 28px 0; color:#667085; font-size:14.5px; line-height:1.65; text-align:center;">
    Tu cuenta para <strong style="color:#344054;">{{ $negocio }}</strong> ya está lista en {{ $appName }}. Empecemos.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0">
          <tr>
            <td style="border-radius:10px; background-color:#5c6ef8;">
              <a href="{{ $loginUrl }}" style="display:inline-block; padding:15px 44px; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none; border-radius:10px; letter-spacing:0.01em;">
                Iniciar sesión
              </a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 12px 0; color:#101828; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; text-align:center;">
    Primeros pasos
  </p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0; border:1px solid #eef0f4; border-radius:10px; overflow:hidden;">
    <tr>
      <td style="padding:12px 16px; border-bottom:1px solid #eef0f4; color:#475467; font-size:13.5px; line-height:1.5;">
        <span style="display:inline-block; width:20px; color:#5c6ef8; font-weight:700;">1</span>
        Cargá tus productos desde <strong style="color:#101828;">Productos</strong>
      </td>
    </tr>
    <tr>
      <td style="padding:12px 16px; border-bottom:1px solid #eef0f4; color:#475467; font-size:13.5px; line-height:1.5;">
        <span style="display:inline-block; width:20px; color:#5c6ef8; font-weight:700;">2</span>
        Empezá a vender desde el <strong style="color:#101828;">Punto de Venta</strong>
      </td>
    </tr>
    <tr>
      <td style="padding:12px 16px; color:#475467; font-size:13.5px; line-height:1.5;">
        <span style="display:inline-block; width:20px; color:#5c6ef8; font-weight:700;">3</span>
        Configurá tu caja desde <strong style="color:#101828;">Caja</strong>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;">
    <tr>
      <td style="padding:14px 16px; background-color:#f4f5ff; border-radius:10px; text-align:center;">
        <p style="margin:0; color:#5c6ef8; font-size:13px; font-weight:700; line-height:1.5;">
          ✨ 7 días de prueba gratis con todas las funciones — sin tarjeta de crédito.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0; color:#98a2b3; font-size:12.5px; line-height:1.65; text-align:center;">
    ¿Tenés dudas? Respondé este correo y te ayudamos.
  </p>
</x-mail-layout>
