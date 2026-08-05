<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\MercadoPagoConexion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoTokenService
{
    /**
     * Devuelve un access_token vigente para la cuenta de MP conectada de la empresa,
     * renovándolo primero si está por vencer. Nunca usar el access_token de la fila
     * directamente para llamar a la API de Point — siempre pasar por acá.
     *
     * Lock de la fila mientras dura el chequeo/refresh: MP invalida el
     * refresh_token una vez usado, así que si dos requests necesitan un token
     * casi al mismo tiempo (dos confirmaciones de Point llegando juntas, por
     * ejemplo) y ambas refrescan en paralelo con el mismo refresh_token viejo,
     * la segunda queda con un token inválido hasta que se reconecte la cuenta
     * a mano. Con el lock, la segunda espera a que la primera termine y relee
     * el token ya renovado en vez de pisarlo.
     */
    public function obtenerTokenValido(Empresa $empresa): ?string
    {
        return DB::transaction(function () use ($empresa) {
            $conexion = MercadoPagoConexion::where('empresa_id', $empresa->id)->lockForUpdate()->first();
            if (!$conexion) return null;

            if ($conexion->expires_at->subMinutes(10)->isPast()) {
                $conexion = $this->refrescar($conexion);
                if (!$conexion) return null;
            }

            return $conexion->access_token;
        });
    }

    private function refrescar(MercadoPagoConexion $conexion): ?MercadoPagoConexion
    {
        try {
            $response = Http::asForm()->post('https://api.mercadopago.com/oauth/token', [
                'client_id'     => env('MP_CLIENT_ID'),
                'client_secret' => env('MP_CLIENT_SECRET'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $conexion->refresh_token,
            ]);

            if (!$response->successful()) {
                Log::warning('MercadoPagoTokenService: fallo al refrescar token', [
                    'empresa_id' => $conexion->empresa_id,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            $conexion->update([
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $conexion->refresh_token,
                'expires_at'    => now()->addSeconds($data['expires_in'] ?? 15552000),
            ]);

            return $conexion->fresh();
        } catch (\Exception $e) {
            Log::warning('MercadoPagoTokenService: excepción al refrescar token: ' . $e->getMessage());
            return null;
        }
    }
}
