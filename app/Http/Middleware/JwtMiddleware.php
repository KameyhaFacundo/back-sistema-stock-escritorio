<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            return response()->json(['message' => 'token expirado'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['message' => 'token invalido'], 401);
        } catch (JWTException $e) {
            // Cubre "no se mandó ningún token" y cualquier otro error de parseo del
            // JWT — antes esto caía en el catch genérico de abajo y devolvía 500 en
            // vez de 401 para el caso más común de todos: pedir un endpoint protegido
            // sin mandar Authorization header.
            return response()->json(['message' => 'token no proporcionado'], 401);
        } catch (Exception $e) {
            return response()->json(['message' => 'token no funciona'], 500);
        }
        return $next($request);
    }
}
