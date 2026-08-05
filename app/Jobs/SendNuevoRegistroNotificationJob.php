<?php

namespace App\Jobs;

use App\Mail\NuevoRegistroMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNuevoRegistroNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $nombreUsuario,
        public string $emailUsuario,
        public string $nombreEmpresa,
        public ?string $tipoEmpresa,
    ) {}

    public function handle(): void
    {
        $adminEmail = config('app.admin_email');
        if (!$adminEmail) {
            return;
        }

        Mail::to($adminEmail)->send(new NuevoRegistroMail(
            $this->nombreUsuario,
            $this->emailUsuario,
            $this->nombreEmpresa,
            $this->tipoEmpresa,
        ));
    }
}
