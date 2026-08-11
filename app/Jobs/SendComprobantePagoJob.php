<?php

namespace App\Jobs;

use App\Mail\ComprobantePagoMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendComprobantePagoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $clienteNombre,
        public float $monto,
        public string $metodoPago,
        public string $fecha,
        public float $saldoRestante,
        public string $empresaNombre,
        public int $idVenta,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new ComprobantePagoMail(
            $this->clienteNombre,
            $this->monto,
            $this->metodoPago,
            $this->fecha,
            $this->saldoRestante,
            $this->empresaNombre,
            $this->idVenta,
        ));
    }
}
