<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class SistemaController extends Controller
{
    /**
     * IP de LAN de esta máquina, para que el dueño del local sepa qué
     * dirección escribir en el navegador de otra caja y conectarla a este
     * mismo backend (mismo criterio que electron/lan-ip.js del launcher,
     * pero resuelto acá para no depender de un puente IPC con Electron).
     *
     * El truco del socket UDP a una IP pública es el estándar portable para
     * esto: no llega a mandar nada (UDP no hace handshake), solo hace que el
     * SO elija y bindee la interfaz de salida real, que es la que leemos.
     */
    public function lanIp(): JsonResponse
    {
        $ip = null;
        $sock = @stream_socket_client('udp://8.8.8.8:80', $errno, $errstr);
        if ($sock) {
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            if ($name) {
                [$candidata] = explode(':', $name);
                if ($candidata !== '0.0.0.0') {
                    $ip = $candidata;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => ['ip' => $ip, 'puerto' => (int) (parse_url(config('app.url'), PHP_URL_PORT) ?? 8000)],
        ]);
    }
}
