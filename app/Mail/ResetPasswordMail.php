<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $token, public string $email)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Restablecer contraseña — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $resetUrl = config('app.frontend_url', 'http://localhost:5173')
            . '/reset-password?token=' . $this->token
            . '&email=' . urlencode($this->email);

        return new Content(
            view: 'mail.reset-password',
            with: ['resetUrl' => $resetUrl],
        );
    }
}
