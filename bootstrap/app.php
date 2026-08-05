<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

// En la app de escritorio, el código (este directorio) se reinstala en cada
// actualización, pero storage/ y .env tienen que sobrevivir esa reinstalación
// (ahí vive la base SQLite real del comercio) — el launcher de Electron los
// redirige a una carpeta persistente vía estas dos env vars. Sin ellas, se
// comporta exactamente igual que un Laravel estándar (dev local, etc.).
// getenv() en vez de $_ENV: variables_order del CLI de PHP no siempre incluye
// "E", así que una env var seteada por el proceso padre (Electron) puede no
// aparecer en $_ENV aunque sí esté seteada — getenv() no depende de esa ini.
if ($storagePath = getenv('APP_STORAGE_PATH')) {
    $app->useStoragePath($storagePath);
}
if ($envPath = getenv('APP_ENV_PATH')) {
    $app->useEnvironmentPath($envPath);
}

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
