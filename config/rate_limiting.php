<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'api' => [
        // 60 requests por minuto para rutas públicas (register y el resto)
        'public' => 'throttle:60,1',

        // 120 requests por minuto para rutas autenticadas
        'auth' => 'throttle:120,1',

        // 10 requests por minuto para login — es el endpoint que se prueba con
        // contraseña (el de mayor superficie para fuerza bruta/credential
        // stuffing), no puede compartir el límite genérico de 60/min del resto
        // de las rutas públicas. Mismo criterio que ya se usaba para login_2fa
        // y forgot_password, un poco más permisivo porque acá hay más margen
        // legítimo de error de tipeo.
        'login' => 'throttle:10,1',

        // 5 requests por minuto para forgot-password
        'forgot_password' => 'throttle:5,1',

        // 5 requests por minuto para verificar el código de 2FA en el login —
        // es el único paso entre una contraseña filtrada/phisheada y la cuenta,
        // no puede compartir el límite genérico de 60/min de las rutas públicas.
        'login_2fa' => 'throttle:5,1',

        // 12 requests por minuto para el asistente de IA del catálogo público —
        // cada mensaje consume cupo real de Gemini, compartido con sugerir
        // precio/categoría; sin este límite dedicado un solo visitante abusivo
        // podría agotar el cupo gratuito de toda la empresa.
        'catalogo_asistente' => 'throttle:12,1',

        // 20 requests por minuto para el asistente de IA interno del sistema —
        // mismo motivo, más generoso que el del catálogo porque es un usuario
        // autenticado (no un visitante anónimo) usándolo para trabajar.
        'asistente_sistema' => 'throttle:20,1',
    ],

];
