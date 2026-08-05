<?php

namespace App\Jobs;

use App\Mail\VerificarEmailMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEmailVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $token,
        public int $userId,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new VerificarEmailMail($this->token, $this->userId));
    }
}
