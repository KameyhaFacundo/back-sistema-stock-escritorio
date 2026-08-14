<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El rol "usuario" (cajero) ya no ve "Etiquetas" en el sidebar ni puede
     * entrar a esa sección — a pedido explícito. Mismo criterio que
     * 2026_08_11_000001_saca_view_dashboard_del_rol_usuario.php: toca la
     * PLANTILLA del rol (rol_permisos, afecta altas nuevas y reasignaciones
     * de rol) y hace un backfill puntual sobre los usuarios YA creados con
     * este rol — sin este backfill, sus permisos directos (permisos_usuarios)
     * seguirían teniendo view-etiquetas aunque la plantilla ya no lo traiga
     * (el rol no otorga permisos por sí solo, ver UsersController::sync).
     *
     * print-etiquetas (el botón de imprimir en sí) se deja como está — sin
     * view-etiquetas la sección ya no es alcanzable, así que queda
     * inofensivo; no hace falta tocarlo para lograr el efecto pedido.
     */
    public function up(): void
    {
        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        if (!$rolUsuarioId) return;

        $permisoId = DB::table('permisos')->where('codigo', 'view-etiquetas')->value('id');
        if ($permisoId) {
            $idsRolUsuario = DB::table('users')->where('id_rol', $rolUsuarioId)->pluck('nro_usu');
            DB::table('permisos_usuarios')
                ->where('id_permiso', $permisoId)
                ->whereIn('id_usuario', $idsRolUsuario)
                ->delete();

            DB::table('rol_permisos')
                ->where('id_rol', $rolUsuarioId)
                ->where('id_permiso', $permisoId)
                ->delete();
        }
    }

    public function down(): void
    {
        // No se revierte — no hay forma de saber si un usuario de este rol
        // tenía view-etiquetas por la plantilla vieja o porque un admin se
        // lo agregó a mano después (mismo criterio que la migración de
        // view-dashboard que sigue este mismo patrón).
    }
};
