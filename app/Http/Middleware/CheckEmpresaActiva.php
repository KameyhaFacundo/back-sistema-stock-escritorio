<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckEmpresaActiva
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && !$user->is_super_admin && $user->empresa) {
            $empresa = $user->empresa;

            if ($empresa->suspendida) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tu cuenta está suspendida. Contactá al administrador.',
                    'suspended' => true,
                ], 403);
            }

            // trial_ends_at se limpia (null) cuando la empresa paga un plan — ver
            // SubscripcionController::webhook(). Si sigue seteada y ya pasó, el
            // trial gratuito terminó y no pagó ningún plan todavía.
            if ($empresa->trial_ends_at && $empresa->trial_ends_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tu período de prueba terminó. Elegí un plan para seguir usando el sistema.',
                    'trial_expired' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
