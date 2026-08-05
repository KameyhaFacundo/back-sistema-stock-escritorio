<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\PagoSuscripcion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PagosDemoSeeder extends Seeder
{
    public function run(): void
    {
        $planes = ['esencial', 'pro', 'ia'];
        $precios = ['esencial' => 9900, 'pro' => 19900, 'ia' => 29900];

        // 1. Primero upgradeamos algunas empresas a planes pagos
        $empresas = Empresa::all();
        if ($empresas->isEmpty()) {
            $this->command?->info('No hay empresas. Ejecuta DatosRealesSeeder primero.');
            return;
        }

        $upgraded = 0;
        foreach ($empresas as $empresa) {
            if (rand(0, 1) === 0 && $empresa->plan === 'free') {
                $empresa->update([
                    'plan' => $planes[array_rand($planes)],
                    'trial_ends_at' => null,
                ]);
                $upgraded++;
            }
        }

        $this->command?->info("Upgraded {$upgraded} empresas a planes pagos.");

        // 2. Generar pagos historicos
        $empresasPagas = Empresa::whereIn('plan', $planes)
            ->orWhereNotNull('trial_ends_at')
            ->get();

        $pagos = [];
        foreach ($empresasPagas as $empresa) {
            $cantidad = rand(2, 12);
            $planActual = in_array($empresa->plan, $planes) ? $empresa->plan : 'pro';
            $planAnterior = 'free';

            for ($i = $cantidad; $i >= 1; $i--) {
                $mesesAtras = $i;
                $fecha = Carbon::now()->subMonths($mesesAtras)->setDay(rand(1, 28));

                // El primer pago (mas antiguo) es un upgrade de free al plan actual
                // Los siguientes son renovaciones
                if ($i === $cantidad) {
                    $planAnterior = 'free';
                    $planPago = $planActual;
                } elseif ($i === (int)($cantidad / 2) && rand(0, 1)) {
                    // Simular un upgrade aleatorio a mitad del historial
                    $nuevoPlan = $planes[array_rand($planes)];
                    $planAnterior = $planPago ?? $planActual;
                    $planPago = $nuevoPlan;
                } else {
                    $planAnterior = $planPago ?? $planActual;
                    $planPago = $planPago ?? $planActual;
                }

                $pagos[] = [
                    'empresa_id'    => $empresa->id,
                    'plan'          => $planPago,
                    'plan_anterior' => $planAnterior,
                    'ciclo'         => rand(0, 2) === 0 ? 'anual' : 'mensual',
                    'monto'         => ($precios[$planPago] ?? 9900) * (rand(0, 2) === 0 ? 10 : 1), // 10x descuento por anual
                    'payment_id'    => 'pay_demo_' . uniqid(),
                    'created_at'    => $fecha,
                    'updated_at'    => $fecha,
                ];

                // Solo algunas empresas tienen pagos este mes
                if (rand(0, 3) === 0 && $i === 0) {
                    $pagos[] = [
                        'empresa_id'    => $empresa->id,
                        'plan'          => $planActual,
                        'plan_anterior' => $planPago ?? $planActual,
                        'ciclo'         => 'mensual',
                        'monto'         => $precios[$planActual] ?? 9900,
                        'payment_id'    => 'pay_demo_' . uniqid(),
                        'created_at'    => Carbon::now()->subDays(rand(0, 25)),
                        'updated_at'    => Carbon::now()->subDays(rand(0, 25)),
                    ];
                }
            }
        }

        PagoSuscripcion::insert($pagos);

        $this->command?->info('Se generaron ' . count($pagos) . ' pagos de suscripcion demo.');
    }
}
