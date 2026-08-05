<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Requiere que el cron del host llame a `php artisan schedule:run`
        // cada minuto (requisito estándar de Laravel) — sin eso, esto nunca
        // se dispara solo. Corre de madrugada para no competir con el uso real.
        $schedule->command('backup:run')->dailyAt('03:00')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
