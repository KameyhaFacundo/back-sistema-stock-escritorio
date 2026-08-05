<?php

return [
    // Vacío por default: sin DSN, el SDK no manda nada a ningún lado (no-op seguro).
    // Se completa con la URL que da Sentry al crear un proyecto (sentry.io, gratis
    // hasta cierto volumen), vía SENTRY_LARAVEL_DSN en el .env de producción.
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Nombre del release, útil para saber en qué versión pasó cada error.
    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // % de requests para performance tracing — bajo por default para no gastar
    // la cuota gratuita de Sentry en tracing en vez de en errores reales.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),

    'send_default_pii' => false,
];
