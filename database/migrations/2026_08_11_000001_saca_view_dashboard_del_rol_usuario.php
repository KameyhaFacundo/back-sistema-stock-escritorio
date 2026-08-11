<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El rol "usuario" (cajero) ya no ve Reportes/Dashboard, ni siquiera la
     * pestaña "Resumen" — a pedido explícito, antes tenía view-dashboard con
     * acceso acotado (ver 2026_08_10_000008_redefine_rol_usuario_y_dashboard_
     * completo.php). Mismo criterio que esa migración: toca la PLANTILLA del
     * rol (rol_permisos, afecta altas nuevas y reasignaciones de rol) y hace
     * un backfill puntual sobre los usuarios YA creados con este rol — sin
     * este backfill, sus permisos directos (permisos_usuarios) seguirían
     * teniendo view-dashboard aunque la plantilla ya no lo traiga (el rol no
     * otorga permisos por sí solo, ver UsuarioSeeder).
     *
     * El front (Login.jsx/OAuthCallback.jsx/PrivateRoute.jsx) ya no depende
     * de que view-dashboard exista para poder loguearse — ahora prueba una
     * lista de rutas en orden y aterriza en la primera que el rol sí tiene
     * (para "usuario" es /pos, ver primeraRutaDisponible en
     * useHasPermiso.jsx) — así que sacarlo acá no lo deja sin poder entrar.
     */
    public function up(): void
    {
        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        if (!$rolUsuarioId) return;

        $dashboardId = DB::table('permisos')->where('codigo', 'view-dashboard')->value('id');
        if ($dashboardId) {
            // Solo a quienes tienen el rol "usuario" — un admin/gerente con
            // view-dashboard directo no se toca.
            $idsRolUsuario = DB::table('users')->where('id_rol', $rolUsuarioId)->pluck('nro_usu');
            DB::table('permisos_usuarios')
                ->where('id_permiso', $dashboardId)
                ->whereIn('id_usuario', $idsRolUsuario)
                ->delete();

            DB::table('rol_permisos')
                ->where('id_rol', $rolUsuarioId)
                ->where('id_permiso', $dashboardId)
                ->delete();
        }
    }

    public function down(): void
    {
        // No se revierte — no hay forma de saber si un usuario de este rol
        // tenía view-dashboard por la plantilla vieja o porque un admin se
        // lo agregó a mano después (mismo criterio que la migración anterior).
    }
};
