<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Sentry\State\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Todas las instalaciones corren el mismo repo contra el mismo
        // proyecto de Sentry (ver config/sentry.php) — sin esto, un error de
        // Palomar y uno de Stock Prueba se verían idénticos, sin forma de
        // saber de qué instalación vino. No-op si SENTRY_LARAVEL_DSN está
        // vacío (\Sentry\configureScope ya maneja ese caso solo). El closure
        // corre recién al mandar un evento, no acá — auth()->user() en ese
        // momento sí está resuelto, aunque acá en boot() todavía no.
        if (function_exists('\Sentry\configureScope')) {
            \Sentry\configureScope(function (Scope $scope): void {
                $empresa = auth()->user()?->empresa?->nombre;
                if ($empresa) $scope->setTag('cliente', $empresa);
            });
        }
    }
}
