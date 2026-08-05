<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defensa en profundidad para /super-admin: SuperAdminController ya llama a
 * checkSuperAdmin() en cada método, pero eso depende de que cada método nuevo
 * se acuerde de hacerlo. Este middleware bloquea el grupo de rutas entero
 * antes de llegar al controller, sin reemplazar esos chequeos.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado'], 403);
        }

        return $next($request);
    }
}
