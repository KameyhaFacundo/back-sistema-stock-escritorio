<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\SistemaController;
use App\Http\Controllers\EtiquetaController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\MercadoPagoConexionController;
use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeudasController;
use App\Http\Controllers\DeudasClientesController;
use App\Http\Controllers\ClientesController;
use App\Http\Controllers\ComprasController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\ProductosController;
use App\Http\Controllers\ProveedoresController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SucursalesController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VentasController;
use App\Http\Controllers\PresupuestosController;
// IA (Gemini) desactivada por ahora — descomentar junto con las rutas de
// más abajo si se vuelve a activar (ver EscanearController/IaController/AsistenteController).
// use App\Http\Controllers\EscanearController;
use App\Http\Controllers\FacturaController;
// use App\Http\Controllers\IaController;
use App\Http\Controllers\MovimientosController;
use App\Http\Controllers\CarritoVaciadoController;
use App\Http\Controllers\PagoPointController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\LotesController;
use App\Http\Controllers\CatalogoController;
// use App\Http\Controllers\AsistenteController;
use App\Http\Controllers\GruposTallesController;
use App\Http\Controllers\TallesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes v1
|--------------------------------------------------------------------------
| Rate-limited, permission-protected, with v1 prefix for future versioning.
| Legacy /api/* routes also work via the same group definition below.
*/

$apiRoutes = function () {

    // ── Públicas ──────────────────────────────────────────────────
    Route::group(['middleware' => config('rate_limiting.api.public')], function () {
        // Ping liviano para que el front detecte si hay conexión real al backend
        // (navigator.onLine solo sabe si hay una interfaz de red, no si realmente
        // hay salida a internet o si el servidor responde).
        Route::get('health', fn () => response()->json(['ok' => true]));

        Route::post('login', [AuthController::class, 'login'])
            ->middleware(config('rate_limiting.api.login'));
        Route::post('login/2fa', [AuthController::class, 'loginVerificar2fa'])
            ->middleware(config('rate_limiting.api.login_2fa'));
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware(config('rate_limiting.api.forgot_password'));
        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware(config('rate_limiting.api.forgot_password'));
        // Segundo paso del cambio de email desde "Mi perfil" — el token es la
        // prueba de identidad (mismo criterio que reset-password), no requiere JWT.
        Route::post('confirmar-email', [UsersController::class, 'confirmarEmail'])
            ->middleware(config('rate_limiting.api.forgot_password'));

        // MP redirige acá tras la autorización — sin JWT, la empresa viaja en `state`
        Route::get('mercadopago/callback', [MercadoPagoConexionController::class, 'callback']);

        Route::post('ventas/webhook-point', [PagoPointController::class, 'webhook']);

        // Catálogo online — página pública sin login, un slug por empresa
        Route::get('catalogo/{slug}', [CatalogoController::class, 'show']);

        // Google OAuth — redirect + callback, sin JWT (el front redirige el
        // navegador acá, no hace fetch).
        Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect']);
        Route::get('auth/google/callback', [GoogleAuthController::class, 'callback']);
    });

    // ── Autenticadas ──────────────────────────────────────────────
    Route::group(['middleware' => ['jwt.verify', config('rate_limiting.api.auth')]], function () {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('me/sucursal', [AuthController::class, 'switchSucursal']);

        Route::prefix('2fa')->group(function () {
            Route::get('estado', [TwoFactorController::class, 'estado']);
            Route::post('activar', [TwoFactorController::class, 'activar']);
            Route::post('confirmar', [TwoFactorController::class, 'confirmar']);
            Route::post('desactivar', [TwoFactorController::class, 'desactivar']);
        });

        Route::get('dashboard/stats', [DashboardController::class, 'stats'])->middleware('permisos.verify:view-dashboard');
        Route::get('dashboard/ranking-productos', [DashboardController::class, 'rankingProductos'])->middleware('permisos.verify:view-dashboard');

        // IA desactivada por ahora (ver nota en los imports, arriba) — descomentar
        // para reactivar sugerencias de precio/categoría y el asistente de IA.
        // Route::post('ia/sugerencias', [IaController::class, 'sugerencias']);
        // Route::post('asistente/preguntar', [AsistenteController::class, 'preguntar'])
        //     ->middleware(config('rate_limiting.api.asistente_sistema'));

        Route::prefix('users')->group(function () {
                Route::get('/',              [UsersController::class, 'index'])   ->middleware('permisos.verify:list-usuarios');
                Route::get('{id}',           [UsersController::class, 'show'])    ->middleware('permisos.verify:view-usuarios');
                Route::post('/',             [UsersController::class, 'store'])   ->middleware('permisos.verify:create-usuarios');
                // Antes de la ruta con wildcard {id} de abajo — si no, "mi-perfil"
                // matchearía ahí como si fuera un id y llamaría a update() en su lugar.
                Route::put('mi-perfil',       [UsersController::class, 'actualizarPerfil']);
                Route::post('configuracion-inicial', [UsersController::class, 'configurarUsuarioInicial']);
                Route::put('{id}',           [UsersController::class, 'update'])  ->middleware('permisos.verify:update-usuarios');
                Route::delete('{id}',        [UsersController::class, 'destroy']) ->middleware('permisos.verify:delete-usuarios');
                Route::put('{id}/restore',   [UsersController::class, 'restore']) ->middleware('permisos.verify:restore-usuarios');
                Route::post('cambiar-password', [UsersController::class, 'cambiarPassword']);
                Route::post('cambiar-pin', [UsersController::class, 'cambiarPin']);
            });

            Route::prefix('roles')->group(function () {
                Route::get('/',         [RolesController::class, 'index'])   ->middleware('permisos.verify:list-roles');
                Route::get('{id}',      [RolesController::class, 'show'])    ->middleware('permisos.verify:view-roles');
                Route::post('/',        [RolesController::class, 'store'])   ->middleware('permisos.verify:create-roles');
                Route::put('{id}',      [RolesController::class, 'update'])  ->middleware('permisos.verify:update-roles');
                Route::delete('{id}',   [RolesController::class, 'destroy']) ->middleware('permisos.verify:delete-roles');
            });

            Route::prefix('permisos')->group(function () {
                Route::get('/',              [PermisosController::class, 'index'])           ->middleware('permisos.verify:list-permisos');
                Route::get('agrupados',      [PermisosController::class, 'indexAgrupados'])  ->middleware('permisos.verify:list-permisos');
                Route::get('mis-permisos',   [PermisosController::class, 'misPermisos']);
                Route::get('usuario/{id}',   [PermisosController::class, 'permisosUsuario']) ->middleware('permisos.verify:list-permisos');
                Route::post('usuario/{id}',  [PermisosController::class, 'agregarPermiso'])  ->middleware('permisos.verify:assign-permisos');
                Route::get('usuario/{id}/historial', [PermisosController::class, 'historialUsuario']) ->middleware('permisos.verify:list-permisos');
            });

            Route::prefix('categorias')->group(function () {
                Route::get('/',        [CategoriasController::class, 'index'])   ->middleware('permisos.verify:list-categorias');
                Route::get('{id}',     [CategoriasController::class, 'show'])    ->middleware('permisos.verify:view-categorias');
                Route::post('/',       [CategoriasController::class, 'store'])   ->middleware('permisos.verify:create-categorias');
                Route::put('{id}',     [CategoriasController::class, 'update'])  ->middleware('permisos.verify:update-categorias');
                Route::delete('{id}',  [CategoriasController::class, 'destroy']) ->middleware('permisos.verify:delete-categorias');
            });

            // Solo indumentaria las usa (ver CreateProductoRequest), pero comparten
            // los permisos de productos en vez de sumar un tier de permisos nuevo
            // para un sub-recurso tan chico.
            Route::prefix('grupos-talles')->group(function () {
                Route::get('/',        [GruposTallesController::class, 'index'])   ->middleware('permisos.verify:list-productos');
                Route::get('{id}',     [GruposTallesController::class, 'show'])    ->middleware('permisos.verify:view-productos');
                Route::post('/',       [GruposTallesController::class, 'store'])   ->middleware('permisos.verify:create-productos');
                Route::put('{id}',     [GruposTallesController::class, 'update'])  ->middleware('permisos.verify:update-productos');
                Route::delete('{id}',  [GruposTallesController::class, 'destroy']) ->middleware('permisos.verify:delete-productos');
            });

            Route::prefix('talles')->group(function () {
                Route::get('/',        [TallesController::class, 'index'])   ->middleware('permisos.verify:list-productos');
                Route::post('/',       [TallesController::class, 'store'])   ->middleware('permisos.verify:create-productos');
                Route::put('{id}',     [TallesController::class, 'update'])  ->middleware('permisos.verify:update-productos');
                Route::delete('{id}',  [TallesController::class, 'destroy']) ->middleware('permisos.verify:delete-productos');
            });

            Route::prefix('productos')->group(function () {
                Route::get('/',                          [ProductosController::class, 'index'])              ->middleware('permisos.verify:list-productos');
                Route::get('{id}',                       [ProductosController::class, 'show'])               ->middleware('permisos.verify:view-productos');
                Route::post('/',                         [ProductosController::class, 'store'])              ->middleware('permisos.verify:create-productos');
                Route::post('bulk',                      [ProductosController::class, 'bulkStore'])          ->middleware('permisos.verify:create-productos');
                Route::post('bulk-precio',                [ProductosController::class, 'bulkUpdatePrecio'])   ->middleware('permisos.verify:update-productos');
                Route::post('codigos-existentes',         [ProductosController::class, 'codigosExistentes'])  ->middleware('permisos.verify:create-productos');
                Route::put('{id}',                       [ProductosController::class, 'update'])             ->middleware('permisos.verify:update-productos');
                Route::delete('{id}',                    [ProductosController::class, 'destroy'])            ->middleware('permisos.verify:delete-productos');
                Route::get('{id}/historial-precios',     [ProductosController::class, 'historialPrecios'])   ->middleware('permisos.verify:view-productos');
                Route::get('{id}/historial-compras',     [ProductosController::class, 'historialCompras'])   ->middleware('permisos.verify:view-productos');
                Route::post('{id}/imagen',               [ProductosController::class, 'subirImagen'])        ->middleware('permisos.verify:update-productos');
                Route::delete('{id}/imagen',              [ProductosController::class, 'eliminarImagen'])     ->middleware('permisos.verify:update-productos');
                // IA desactivada por ahora (ver nota junto a los imports) — descomentar para reactivar.
                // Route::post('{id}/imagen-ia',             [ProductosController::class, 'generarImagenIa'])    ->middleware('permisos.verify:update-productos');
                // Route::post('{id}/imagen-combo-ia',       [ProductosController::class, 'generarImagenComboIa'])->middleware('permisos.verify:update-productos');
            });

            Route::prefix('proveedores')->group(function () {
                Route::get('/',        [ProveedoresController::class, 'index'])   ->middleware('permisos.verify:list-proveedores');
                Route::get('{id}',     [ProveedoresController::class, 'show'])    ->middleware('permisos.verify:view-proveedores');
                Route::post('/',       [ProveedoresController::class, 'store'])   ->middleware('permisos.verify:create-proveedores');
                Route::put('{id}',     [ProveedoresController::class, 'update'])  ->middleware('permisos.verify:update-proveedores');
                Route::delete('{id}',  [ProveedoresController::class, 'destroy']) ->middleware('permisos.verify:delete-proveedores');
            });

            Route::prefix('clientes')->group(function () {
                Route::get('/',        [ClientesController::class, 'index'])   ->middleware('permisos.verify:list-clientes');
                Route::get('{id}',     [ClientesController::class, 'show'])    ->middleware('permisos.verify:view-clientes');
                Route::get('{id}/puntos', [ClientesController::class, 'puntos'])->middleware('permisos.verify:view-clientes');
                Route::post('/',       [ClientesController::class, 'store'])   ->middleware('permisos.verify:create-clientes');
                Route::put('{id}',     [ClientesController::class, 'update'])  ->middleware('permisos.verify:update-clientes');
                Route::delete('{id}',  [ClientesController::class, 'destroy']) ->middleware('permisos.verify:delete-clientes');
            });

            Route::prefix('compras')->group(function () {
                Route::get('/',                     [ComprasController::class, 'index'])         ->middleware('permisos.verify:list-compras');
                Route::get('{id}',                  [ComprasController::class, 'show'])          ->middleware('permisos.verify:view-compras');
                Route::post('/',                    [ComprasController::class, 'store'])         ->middleware('permisos.verify:create-compras');
                Route::put('{id}',                  [ComprasController::class, 'update'])        ->middleware('permisos.verify:update-compras');
                Route::delete('{id}',               [ComprasController::class, 'destroy'])       ->middleware('permisos.verify:delete-compras');
                Route::put('{id}/change-status',    [ComprasController::class, 'changeStatus'])  ->middleware('permisos.verify:change-status-compras');
                // Devolución parcial (una o varias líneas) — permiso propio y
                // separado de change-status-compras, mismo criterio que
                // devolver-ventas: un cajero puede devolver mercadería sin
                // poder cambiar el estado completo de una compra.
                Route::post('{id}/devolucion',      [ComprasController::class, 'devolucion'])    ->middleware('permisos.verify:devolver-compras');
                Route::post('{id}/comprobante',     [ComprasController::class, 'subirComprobante'])   ->middleware('permisos.verify:update-compras');
                Route::delete('{id}/comprobante',   [ComprasController::class, 'eliminarComprobante']) ->middleware('permisos.verify:update-compras');
                // IA desactivada por ahora (ver nota junto a los imports) — descomentar para reactivar.
                // Route::post('escanear',             [EscanearController::class, 'factura'])      ->middleware('permisos.verify:create-compras');
            });

            Route::prefix('deudas-clientes')->group(function () {
                Route::get('/',                             [DeudasClientesController::class, 'index'])             ->middleware('permisos.verify:list-clientes');
                Route::get('resumen',                       [DeudasClientesController::class, 'resumen'])           ->middleware('permisos.verify:list-clientes');
                Route::post('{id_venta}/cobrar',            [DeudasClientesController::class, 'cobrar'])            ->middleware('permisos.verify:update-ventas');
                Route::post('{id_venta}/actualizar-precios', [DeudasClientesController::class, 'actualizarPrecios']) ->middleware('permisos.verify:update-ventas');
                Route::post('{id_venta}/revertir-precios',   [DeudasClientesController::class, 'revertirPrecios'])   ->middleware('permisos.verify:update-ventas');
            });

            Route::prefix('deudas')->group(function () {
                Route::get('/',                   [DeudasController::class, 'index'])    ->middleware('permisos.verify:list-proveedores');
                Route::get('resumen',             [DeudasController::class, 'resumen'])  ->middleware('permisos.verify:list-proveedores');
                Route::post('{id_compra}/pagar',  [DeudasController::class, 'pagar'])    ->middleware('permisos.verify:update-compras');
            });

            Route::prefix('caja')->group(function () {
                Route::get('turno-activo', [CajaController::class, 'turnoActivo'])       ->middleware('permisos.verify:list-caja');
                Route::get('historial',    [CajaController::class, 'historial'])         ->middleware('permisos.verify:list-historial-caja');
                Route::post('abrir',       [CajaController::class, 'abrir'])             ->middleware('permisos.verify:create-caja');
                Route::put('cerrar',       [CajaController::class, 'cerrar'])            ->middleware('permisos.verify:update-caja');
                Route::post('movimiento',  [CajaController::class, 'agregarMovimiento']) ->middleware('permisos.verify:create-caja');
            });

            // Sin DELETE acá a propósito: el historial de movimientos es inmutable
            // (ver el tour de Movimientos en el front) — borrar un renglón nunca
            // revertía el stock real, solo la evidencia de que había pasado.
            Route::prefix('movimientos')->group(function () {
                Route::get('/',              [MovimientosController::class, 'index'])         ->middleware('permisos.verify:list-movimientos');
                Route::post('/',             [MovimientosController::class, 'store'])         ->middleware('permisos.verify:create-movimientos');
                Route::post('bulk',          [MovimientosController::class, 'bulkStore'])      ->middleware('permisos.verify:create-movimientos');
                Route::post('transferencia', [MovimientosController::class, 'transferencia'])  ->middleware('permisos.verify:create-movimientos');
            });

            // Toma de inventario — guarda el conteo físico en lote creando
            // ajustes automáticos por diferencia (mismo permiso que movimientos).
            Route::post('inventario/guardar', [InventarioController::class, 'guardar'])
                ->middleware('permisos.verify:create-movimientos');

            // Auditoría de carritos vaciados en el POS — el registro (store) lo
            // puede generar cualquiera con sesión (es automático al vaciar el
            // carrito, no un permiso a otorgar); listar sí está restringido.
            Route::prefix('carritos-vaciados')->group(function () {
                Route::post('/', [CarritoVaciadoController::class, 'store']);
                Route::get('/',  [CarritoVaciadoController::class, 'index'])
                    ->middleware('permisos.verify:list-carritos-vaciados');
            });

            // Plantillas de etiquetas de precio
            Route::get('plantillas-etiqueta', [EtiquetaController::class, 'index'])   ->middleware('permisos.verify:view-etiquetas');
            Route::post('plantillas-etiqueta', [EtiquetaController::class, 'store'])   ->middleware('permisos.verify:view-etiquetas');
            Route::put('plantillas-etiqueta/{id}', [EtiquetaController::class, 'update']) ->middleware('permisos.verify:view-etiquetas');
            Route::delete('plantillas-etiqueta/{id}', [EtiquetaController::class, 'destroy']) ->middleware('permisos.verify:view-etiquetas');

            // Datos para imprimir etiquetas (código, nombre, precio)
            Route::get('productos/etiquetas', [ProductosController::class, 'etiquetas'])->middleware('permisos.verify:print-etiquetas');

            Route::prefix('sucursales')->group(function () {
                Route::get('/',       [SucursalesController::class, 'index'])   ->middleware('permisos.verify:list-sucursales');
                Route::post('/',      [SucursalesController::class, 'store'])   ->middleware('permisos.verify:create-sucursales');
                Route::put('{id}',    [SucursalesController::class, 'update'])  ->middleware('permisos.verify:update-sucursales');
                Route::delete('{id}', [SucursalesController::class, 'destroy']) ->middleware('permisos.verify:delete-sucursales');
            });

            // Lotes de inventario
            Route::prefix('lotes')->group(function () {
                Route::get('/',                  [LotesController::class, 'index'])           ->middleware('permisos.verify:list-productos');
                Route::get('producto/{id}',      [LotesController::class, 'porProducto'])     ->middleware('permisos.verify:list-productos');
                Route::get('proximos-vencer',    [LotesController::class, 'proximosAVencer']) ->middleware('permisos.verify:list-productos');
                Route::post('/',                 [LotesController::class, 'store'])           ->middleware('permisos.verify:create-movimientos');
                Route::put('{id}',               [LotesController::class, 'update'])          ->middleware('permisos.verify:update-movimientos');
                Route::delete('{id}',            [LotesController::class, 'destroy'])         ->middleware('permisos.verify:delete-movimientos');
            });

            // Facturación electrónica ARCA — sin permiso propio (no hay tier
            // "facturas" en PermisoSeeder), reusa los de ventas: el ítem de
            // Facturas en el sidebar ya está gateado por 'verVentas' en el
            // front (ver PERMISOS_MAP en useHasPermiso.jsx), esto lo alinea
            // en el back para que no sea solo cosmético.
            Route::prefix('facturas')->group(function () {
                Route::get('/',         [FacturaController::class, 'index']) ->middleware('permisos.verify:list-ventas');
                Route::get('/ultima',   [FacturaController::class, 'ultima']) ->middleware('permisos.verify:list-ventas');
                Route::get('/estado',   [FacturaController::class, 'estado']) ->middleware('permisos.verify:list-ventas');
                Route::get('/{id}',     [FacturaController::class, 'show']) ->middleware('permisos.verify:list-ventas');
                Route::post('/emitir',  [FacturaController::class, 'emitir']) ->middleware('permisos.verify:create-ventas');
                Route::post('/{id}/nota-credito', [FacturaController::class, 'emitirNotaCredito']) ->middleware('permisos.verify:create-ventas');
            });

            Route::put('empresa', [EmpresaController::class, 'update'])->middleware('permisos.verify:update-configuracion');
            Route::post('empresa/logo', [EmpresaController::class, 'subirLogo'])->middleware('permisos.verify:update-configuracion');
            Route::delete('empresa/logo', [EmpresaController::class, 'eliminarLogo'])->middleware('permisos.verify:update-configuracion');
            Route::post('empresa/arca', [EmpresaController::class, 'actualizarArca'])->middleware('permisos.verify:update-configuracion');
            Route::delete('empresa/arca', [EmpresaController::class, 'eliminarArca'])->middleware('permisos.verify:update-configuracion');
            Route::get('empresa/exportar-datos', [EmpresaController::class, 'exportarDatos'])->middleware('permisos.verify:update-configuracion');
            Route::get('empresa/catalogo', [CatalogoController::class, 'config'])->middleware('permisos.verify:view-configuracion');
            Route::put('empresa/catalogo', [CatalogoController::class, 'actualizar'])->middleware('permisos.verify:update-configuracion');
            Route::get('sistema/lan-ip', [SistemaController::class, 'lanIp'])->middleware('permisos.verify:view-configuracion');

            Route::prefix('mercadopago')->group(function () {
                Route::get('estado',         [MercadoPagoConexionController::class, 'estado'])           ->middleware('permisos.verify:update-configuracion');
                Route::get('conectar',       [MercadoPagoConexionController::class, 'conectar'])         ->middleware('permisos.verify:update-configuracion');
                Route::delete('desconectar', [MercadoPagoConexionController::class, 'desconectar'])      ->middleware('permisos.verify:update-configuracion');
                Route::get('dispositivos',   [MercadoPagoConexionController::class, 'dispositivos'])     ->middleware('permisos.verify:update-configuracion');
                Route::put('dispositivo',    [MercadoPagoConexionController::class, 'guardarDispositivo'])->middleware('permisos.verify:update-configuracion');
            });

            Route::prefix('ventas')->group(function () {
                Route::get('/',                  [VentasController::class, 'index'])         ->middleware('permisos.verify:list-ventas');
                Route::get('{id}',               [VentasController::class, 'show'])          ->middleware('permisos.verify:view-ventas');
                Route::post('/',                 [VentasController::class, 'store'])         ->middleware('permisos.verify:create-ventas');
                // "Override de gerente" para descuentos — confirma la contraseña de OTRO
                // usuario de la empresa, no hace login como esa persona (ver el método).
                // Throttle propio y más estricto que el del grupo padre: es un endpoint
                // que compara contraseñas, no puede quedar abierto a fuerza bruta.
                Route::post('autorizar-descuento', [VentasController::class, 'autorizarDescuento']) ->middleware(['permisos.verify:create-ventas', 'throttle:5,1']);
                Route::put('{id}',               [VentasController::class, 'update'])        ->middleware('permisos.verify:update-ventas');
                // Sin DELETE a propósito: borrar una venta directo dejaba el stock y la
                // caja que ya había afectado sin revertir (a diferencia de /anular, que sí
                // hace la reversión completa) — mismo criterio que con /movimientos.
                Route::put('{id}/change-status', [VentasController::class, 'changeStatus'])  ->middleware('permisos.verify:change-status-ventas');
                Route::post('{id}/anular',       [VentasController::class, 'anular'])        ->middleware('permisos.verify:anular-ventas');
                // Devolución parcial (una o varias líneas, no necesariamente toda la
                // venta) — permiso propio y separado de anular-ventas: un negocio puede
                // querer que un cajero haga devoluciones sin poder anular una venta entera.
                Route::post('{id}/devolucion',   [VentasController::class, 'devolucion'])    ->middleware('permisos.verify:devolver-ventas');

                Route::prefix('point')->group(function () {
                    Route::post('crear-intento',          [PagoPointController::class, 'crearIntento']) ->middleware('permisos.verify:create-ventas');
                    Route::get('intento/{id}/estado',     [PagoPointController::class, 'estado'])       ->middleware('permisos.verify:create-ventas');
                    Route::post('intento/{id}/cancelar',  [PagoPointController::class, 'cancelar'])     ->middleware('permisos.verify:create-ventas');
                });

                Route::prefix('qr')->group(function () {
                    Route::post('crear-intento', [PagoPointController::class, 'crearIntentoQr']) ->middleware('permisos.verify:create-ventas');
                });
            });

            Route::prefix('presupuestos')->group(function () {
                Route::get('/',            [PresupuestosController::class, 'index'])     ->middleware('permisos.verify:list-presupuestos');
                Route::get('{id}',         [PresupuestosController::class, 'show'])       ->middleware('permisos.verify:view-presupuestos');
                Route::post('/',           [PresupuestosController::class, 'store'])      ->middleware('permisos.verify:create-presupuestos');
                Route::put('{id}',         [PresupuestosController::class, 'update'])     ->middleware('permisos.verify:update-presupuestos');
                Route::delete('{id}',      [PresupuestosController::class, 'destroy'])    ->middleware('permisos.verify:delete-presupuestos');
                // Mismo permiso que crear una venta manual — convertir es, en la
                // práctica, registrar la venta que el presupuesto ya tenía armada.
                Route::post('{id}/convertir', [PresupuestosController::class, 'convertir'])->middleware('permisos.verify:create-ventas');
            });

    }); // jwt.verify
};

// ── Exponer en /api/v1 (nuevo) y /api (legado) ──
// RouteServiceProvider ya agrega el prefijo 'api', así que acá solo versionamos
Route::prefix('v1')->group($apiRoutes);
$apiRoutes(); // legacy sin prefijo extra
