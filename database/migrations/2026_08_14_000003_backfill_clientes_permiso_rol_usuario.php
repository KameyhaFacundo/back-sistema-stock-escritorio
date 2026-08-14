<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026_08_10_000008_redefine_rol_usuario_y_dashboard_completo.php le dio
     * acceso a Clientes (list/view/create/update) a la PLANTILLA del rol
     * "usuario" (rol_permisos) — a propósito, esa migración dice
     * explícitamente que NO toca los permisos directos de usuarios ya
     * existentes en ese momento (son esos, permisos_usuarios, los que
     * deciden el acceso real — el rol no otorga nada por sí solo). Cualquier
     * cuenta con rol "usuario" creada ANTES de esa migración se quedó sin
     * poder ver/elegir clientes en el POS para siempre, sin que nada lo
     * arreglara solo — reportado en vivo: "en modo usuario, en punto de
     * venta no me aparecen los clientes". Esto backfillea esos cuatro
     * permisos a todo usuario del rol "usuario" que todavía no los tenga.
     */
    public function up(): void
    {
        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        if (!$rolUsuarioId) return;

        $codigos = ['list-clientes', 'view-clientes', 'create-clientes', 'update-clientes'];
        $idsPermisos = DB::table('permisos')->whereIn('codigo', $codigos)->pluck('id');
        if ($idsPermisos->isEmpty()) return;

        $idsUsuarios = DB::table('users')->where('id_rol', $rolUsuarioId)->pluck('nro_usu');
        if ($idsUsuarios->isEmpty()) return;

        foreach ($idsPermisos as $idPermiso) {
            $tienenYa = DB::table('permisos_usuarios')
                ->where('id_permiso', $idPermiso)
                ->whereIn('id_usuario', $idsUsuarios)
                ->pluck('id_usuario');
            $faltantes = $idsUsuarios->diff($tienenYa);

            $filas = $faltantes->map(fn($idUsuario) => [
                'id_usuario' => $idUsuario,
                'id_permiso' => $idPermiso,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (!empty($filas)) {
                DB::table('permisos_usuarios')->insert($filas);
            }
        }
    }

    public function down(): void
    {
        // No se revierte — no hay forma de saber si un usuario de este rol
        // tenía estos permisos por este backfill o porque un admin se los
        // dio a mano después (mismo criterio que las demás migraciones de
        // este tipo).
    }
};
