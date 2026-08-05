<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// API pura sin frontend propio en el modelo SaaS original (lo servía
// front-sistema-stock aparte) — pero en la app de escritorio el build de
// React se copia dentro de public/ (ver escritorio-launcher/scripts/build-resources)
// y este mismo backend lo sirve desde el mismo origen. Si ese build está
// presente, cualquier GET que no matchee /api/* devuelve su index.html
// (fallback estándar de SPA); si no está (dev del backend solo, sin el
// front empaquetado), se mantiene la respuesta JSON de antes.
if (file_exists(public_path('index.html'))) {
    Route::get('/{any}', fn () => response()->file(public_path('index.html')))
        ->where('any', '^(?!api).*$');
} else {
    Route::get('/', fn () => response()->json(['ok' => true, 'app' => config('app.name')]));
}
