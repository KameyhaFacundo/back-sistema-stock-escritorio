<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Se manda automático apenas se registra un cobro de deuda (ver
// DeudasClientesController::cobrar) — no depende de que el empleado se
// acuerde de avisarle al cliente, ni de que quiera avisarle. Si el cliente
// nunca pagó eso, este mail es la forma en que se entera sin que nadie del
// local tenga que decírselo.
class ComprobantePagoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $clienteNombre,
        public float $monto,
        public string $metodoPago,
        public string $fecha,
        public float $saldoRestante,
        public string $empresaNombre,
        public int $idVenta,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Recibimos tu pago — {$this->empresaNombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.comprobante-pago',
            with: [
                'clienteNombre'  => $this->clienteNombre,
                'monto'          => number_format($this->monto, 2, ',', '.'),
                'metodoPago'     => match ($this->metodoPago) {
                    'efectivo'      => 'Efectivo',
                    'transferencia' => 'Transferencia',
                    'tarjeta'       => 'Tarjeta',
                    'qr'            => 'QR',
                    default         => ucfirst($this->metodoPago),
                },
                'fecha'          => $this->fecha,
                'saldoRestante'  => number_format($this->saldoRestante, 2, ',', '.'),
                'empresaNombre'  => $this->empresaNombre,
                'idVenta'        => $this->idVenta,
            ],
        );
    }
}
