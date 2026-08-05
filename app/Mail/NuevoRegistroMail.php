<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NuevoRegistroMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreUsuario,
        public string $emailUsuario,
        public string $nombreEmpresa,
        public ?string $tipoEmpresa,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuevo registro en ' . config('app.name') . ': ' . $this->nombreEmpresa,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.nuevo-registro',
            with: [
                'nombreUsuario' => $this->nombreUsuario,
                'emailUsuario'  => $this->emailUsuario,
                'nombreEmpresa' => $this->nombreEmpresa,
                'tipoEmpresa'   => $this->tipoEmpresa,
                'appName'       => config('app.name', 'Kamex Solutions'),
            ],
        );
    }
}
