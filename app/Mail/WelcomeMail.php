<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombre,
        public string $negocio,
        public string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '¡Bienvenido a ' . config('app.name') . '! 🎉',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.welcome',
            with: [
                'nombre'    => $this->nombre,
                'negocio'   => $this->negocio,
                'email'     => $this->email,
                'loginUrl'  => config('app.frontend_url', 'http://localhost:5173') . '/signin',
                'appName'   => config('app.name', 'Kamex Solutions'),
            ],
        );
    }
}
