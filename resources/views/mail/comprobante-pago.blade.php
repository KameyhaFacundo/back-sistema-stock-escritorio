{{-- Mismo criterio que reset-password.blade.php: logo incrustado (Content-ID),
     no como <img> apuntando a una URL externa. --}}
@php $logoCid = $message->embed(
    \Illuminate\Mail\Attachment::fromPath(resource_path('branding/logo.png'))->as('logo.png')->withMime('image/png')
); @endphp
<x-mail-layout :logo-url="$logoCid">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0">
          <tr>
            <td style="width:52px; height:52px; border-radius:14px; background-color:#f0fdf4; text-align:center; vertical-align:middle; font-size:24px; line-height:52px;">
              ✅
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <h1 style="margin:0 0 10px 0; color:#101828; font-size:21px; font-weight:800; letter-spacing:-0.02em; text-align:center;">
    Recibimos tu pago
  </h1>
  <p style="margin:0 0 28px 0; color:#667085; font-size:14.5px; line-height:1.65; text-align:center;">
    Hola {{ $clienteNombre }}, te confirmamos que registramos un pago tuyo en <strong style="color:#344054;">{{ $empresaNombre }}</strong>.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px 0; border:1px solid #eef0f4; border-radius:12px; overflow:hidden;">
    <tr>
      <td style="padding:16px 20px; border-bottom:1px solid #eef0f4;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="color:#98a2b3; font-size:13px;">Monto pagado</td>
            <td align="right" style="color:#101828; font-size:16px; font-weight:800;">${{ $monto }}</td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 20px; border-bottom:1px solid #eef0f4;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="color:#98a2b3; font-size:13px;">Método</td>
            <td align="right" style="color:#344054; font-size:13.5px; font-weight:600;">{{ $metodoPago }}</td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 20px; border-bottom:1px solid #eef0f4;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="color:#98a2b3; font-size:13px;">Fecha</td>
            <td align="right" style="color:#344054; font-size:13.5px; font-weight:600;">{{ $fecha }}</td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 20px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="color:#98a2b3; font-size:13px;">Saldo pendiente</td>
            <td align="right" style="color:{{ $saldoRestante > 0 ? '#dc6803' : '#12b76a' }}; font-size:13.5px; font-weight:700;">${{ $saldoRestante }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 4px 0;">
    <tr>
      <td style="padding:14px 16px; background-color:#fffaeb; border:1px solid #fef0c7; border-radius:10px; text-align:center;">
        <p style="margin:0; color:#b54708; font-size:12.5px; line-height:1.6;">
          ⚠️ Si vos no hiciste este pago, o el monto no coincide con lo que pagaste, comunicate con {{ $empresaNombre }} a la brevedad.
        </p>
      </td>
    </tr>
  </table>
</x-mail-layout>
