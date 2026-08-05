<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificarEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $token, public int $userId)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmá tu cuenta — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        // Mismo endpoint/página que la confirmación de cambio de email
        // (UsersController::confirmarEmail) — sin email_pendiente, solo
        // marca la cuenta como verificada.
        $confirmUrl = config('app.frontend_url', 'http://localhost:5173')
            . '/confirmar-email?token=' . $this->token
            . '&id=' . $this->userId;

        return new Content(
            view: 'mail.verificar-email',
            with: ['confirmUrl' => $confirmUrl],
        );
    }
}
