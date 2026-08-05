{{-- Logo incrustado (Content-ID), no una URL externa — ver welcome.blade.php --}}
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
              🚀
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <h1 style="margin:0 0 10px 0; color:#101828; font-size:21px; font-weight:800; letter-spacing:-0.02em; text-align:center;">
    Nuevo registro en {{ $appName }}
  </h1>
  <p style="margin:0 0 24px 0; color:#667085; font-size:14.5px; line-height:1.65; text-align:center;">
    Se acaba de crear una cuenta nueva.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0; border:1px solid #eef0f4; border-radius:10px; overflow:hidden;">
    <tr>
      <td style="padding:12px 16px; border-bottom:1px solid #eef0f4; color:#475467; font-size:13.5px; line-height:1.5;">
        <strong style="color:#101828;">Empresa:</strong> {{ $nombreEmpresa }}
      </td>
    </tr>
    @if($tipoEmpresa)
    <tr>
      <td style="padding:12px 16px; border-bottom:1px solid #eef0f4; color:#475467; font-size:13.5px; line-height:1.5;">
        <strong style="color:#101828;">Rubro:</strong> {{ $tipoEmpresa }}
      </td>
    </tr>
    @endif
    <tr>
      <td style="padding:12px 16px; border-bottom:1px solid #eef0f4; color:#475467; font-size:13.5px; line-height:1.5;">
        <strong style="color:#101828;">Usuario:</strong> {{ $nombreUsuario }}
      </td>
    </tr>
    <tr>
      <td style="padding:12px 16px; color:#475467; font-size:13.5px; line-height:1.5;">
        <strong style="color:#101828;">Email:</strong> {{ $emailUsuario }}
      </td>
    </tr>
  </table>

  <p style="margin:0; color:#98a2b3; font-size:12.5px; line-height:1.65; text-align:center;">
    Empieza con el Plan Free (prueba de 7 días).
  </p>
</x-mail-layout>
