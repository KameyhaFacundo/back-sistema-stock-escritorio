<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class VerificarPermisos
{
    public function handle(Request $request, Closure $next, ...$permisos): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            // Verificar si tiene al menos uno de los permisos requeridos
            // (el rol "Administrador" ya trae todos los permisos sincronizados)
            if ($user->chequearPermisos($permisos)) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'No tienes los permisos necesarios.',
            ], 403);
        } catch (JWTException $e) {
            if ($e instanceof TokenInvalidException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido',
                ], 401);
            }

            if ($e instanceof TokenExpiredException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token expirado',
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al autenticar el usuario.',
            ], 401);
        }
    }
}
