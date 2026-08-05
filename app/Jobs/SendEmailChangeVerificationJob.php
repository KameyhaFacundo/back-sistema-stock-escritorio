<?php

namespace App\Jobs;

use App\Mail\ConfirmarEmailMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEmailChangeVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $emailNuevo,
        public string $token,
        public int $userId,
    ) {}

    public function handle(): void
    {
        Mail::to($this->emailNuevo)->send(new ConfirmarEmailMail($this->token, $this->userId));
    }
}
