<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        // Antes estos usaban 'throttle:N,1' directo en config/rate_limiting.php.
        // Ese formato resuelve la clave del contador SOLO por dominio+IP (sin
        // dominio configurado acá, queda fija en "|127.0.0.1" para cualquier
        // instalación de escritorio) — asi que /health (pingueado cada 20s por
        // useOnlineStatus), /login, /login/2fa, /forgot-password, etc. TODOS
        // compartían un único contador, y encima /login quedaba envuelto por
        // el grupo "public" (throttle:60,1) Y su propio middleware
        // (throttle:10,1) — dos throttles distintos pegándole a la MISMA clave,
        // así que cada intento de login real gastaba 2 lugares del cupo en vez
        // de 1. Resultado: "Demasiados intentos" mucho antes de los 10
        // intentos reales que el número prometía. Un limiter con nombre le da
        // una clave propia a cada uno (namespaced por nombre + IP acá).
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('login_2fa', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('forgot_password', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('catalogo_asistente', fn (Request $request) => Limit::perMinute(12)->by($request->ip()));
        RateLimiter::for('asistente_sistema', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
