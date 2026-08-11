<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redefine qué trae por defecto el rol "Usuario" (cajero básico) y agrega
     * view-dashboard-completo — sin él, el Dashboard solo muestra la pestaña
     * "Resumen" (ver Dashboard.jsx). Solo toca dos cosas, a propósito:
     *
     * 1) La PLANTILLA del rol "Usuario" (rol_permisos) — afecta usuarios
     *    NUEVOS creados con ese rol de acá en más, y a cualquier usuario
     *    existente al que se le vuelva a asignar el rol desde la pantalla de
     *    Usuarios ("Cambiar el rol aplica sus permisos por defecto"). NO toca
     *    los permisos directos de usuarios ya existentes — si un admin ya
     *    personalizó a mano los permisos de alguien, esto no se los pisa (ver
     *    el comentario en UsuarioSeeder: el rol no otorga permisos por sí
     *    solo, son los permisos directos del usuario los que deciden el
     *    acceso real).
     *
     * 2) view-dashboard-completo se le da a todo usuario que YA tenía
     *    view-dashboard directo y NO tiene el rol "usuario" — admin/gerente/
     *    permisos personalizados no pierden nada; los del rol "usuario" sí
     *    quedan acotados a "Resumen" nomás, que es el cambio pedido.
     */
    public function up(): void
    {
        $permisoCompletoId = DB::table('permisos')->where('codigo', 'view-dashboard-completo')->value('id');
        if (!$permisoCompletoId) {
            $permisoCompletoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'view-dashboard-completo',
                'nombre'     => 'Ver todas las pestañas del dashboard (no solo Resumen)',
                'grupo'      => 'dashboard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');

        // Backfill de view-dashboard-completo — todo el que ya veía el
        // dashboard entero (por tener view-dashboard) y no es del rol
        // "usuario" lo sigue viendo entero.
        $dashboardId = DB::table('permisos')->where('codigo', 'view-dashboard')->value('id');
        if ($dashboardId) {
            $usuariosConDashboard = DB::table('permisos_usuarios')
                ->where('id_permiso', $dashboardId)
                ->pluck('id_usuario');

            if ($rolUsuarioId) {
                $idsRolUsuario = DB::table('users')->where('id_rol', $rolUsuarioId)->pluck('nro_usu');
                $usuariosConDashboard = $usuariosConDashboard->diff($idsRolUsuario);
            }

            $usuariosConCompleto = DB::table('permisos_usuarios')
                ->where('id_permiso', $permisoCompletoId)
                ->pluck('id_usuario');

            $faltantes = $usuariosConDashboard->diff($usuariosConCompleto);
            $filas = $faltantes->map(fn ($idUsuario) => [
                'id_usuario' => $idUsuario,
                'id_permiso' => $permisoCompletoId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();
            if (!empty($filas)) {
                DB::table('permisos_usuarios')->insert($filas);
            }
        }

        // Resincroniza la PLANTILLA del rol "usuario" — no los usuarios ya
        // asignados (ver el comentario de arriba).
        if ($rolUsuarioId) {
            $codigosNuevos = [
                'view-dashboard',
                'list-ventas', 'view-ventas', 'create-ventas',
                'list-caja', 'create-caja', 'update-caja',
                'list-categorias', 'view-categorias',
                'list-clientes', 'view-clientes', 'create-clientes', 'update-clientes',
                'list-productos', 'view-productos',
                'view-etiquetas', 'print-etiquetas',
            ];
            $idsNuevos = DB::table('permisos')->whereIn('codigo', $codigosNuevos)->pluck('id');

            DB::table('rol_permisos')->where('id_rol', $rolUsuarioId)->delete();
            // Sin timestamps a propósito — rol_permisos es una pivot simple
            // (clave primaria compuesta id_rol+id_permiso, ver su migración).
            $filas = $idsNuevos->map(fn ($idPermiso) => [
                'id_rol'     => $rolUsuarioId,
                'id_permiso' => $idPermiso,
            ])->all();
            if (!empty($filas)) {
                DB::table('rol_permisos')->insert($filas);
            }
        }
    }

    public function down(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'view-dashboard-completo')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
        // La plantilla del rol "usuario" no se revierte a la lista vieja — no
        // hay forma de saber si cambió por esto o por edición manual después.
    }
};
