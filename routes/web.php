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

// API pura sin frontend propio (lo sirve front-sistema-stock aparte) — nunca
// hubo una vista 'welcome' acá, así que '/' devolvía 500 en vez de una
// respuesta útil.
Route::get('/', fn () => response()->json(['ok' => true, 'app' => config('app.name')]));
