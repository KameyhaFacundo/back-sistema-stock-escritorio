{{-- Mismo criterio que mail/confirmar-email.blade.php: el logo va incrustado
     (Content-ID), no como <img> apuntando a una URL externa. --}}
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
              ✅
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <h1 style="margin:0 0 10px 0; color:#101828; font-size:21px; font-weight:800; letter-spacing:-0.02em; text-align:center;">
    Confirmá tu cuenta
  </h1>
  <p style="margin:0 0 28px 0; color:#667085; font-size:14.5px; line-height:1.65; text-align:center;">
    Creaste una cuenta en <strong style="color:#344054;">{{ config('app.name') }}</strong> con este correo. Tocá el botón para confirmar que es tuyo.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0">
          <tr>
            <td style="border-radius:10px; background-color:#5c6ef8;">
              <a href="{{ $confirmUrl }}" style="display:inline-block; padding:15px 44px; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none; border-radius:10px; letter-spacing:0.01em;">
                Confirmar cuenta
              </a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0;">
    <tr>
      <td style="padding:14px 16px; background-color:#fafafa; border:1px solid #eef0f4; border-radius:10px; text-align:center;">
        <p style="margin:0; color:#98a2b3; font-size:12.5px; line-height:1.6;">
          ⏱ Este enlace expira en <strong style="color:#667085;">60 minutos</strong>. Si no creaste esta cuenta, podés ignorar este correo.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0; color:#c0c5ce; font-size:11.5px; line-height:1.6; text-align:center; word-break:break-all;">
    ¿El botón no funciona? Copiá este enlace:<br>
    <a href="{{ $confirmUrl }}" style="color:#98a2b3; text-decoration:underline;">{{ $confirmUrl }}</a>
  </p>
</x-mail-layout>
