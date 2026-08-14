<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Exportar" en Productos no tenía permiso propio — cualquiera con
     * acceso a la pantalla (incluido el rol "usuario") podía usarlo. Mismo
     * criterio que 2026_08_14_000002 (import/export de Clientes): permiso
     * nuevo, repartido a todos los roles MENOS "usuario", tanto en la
     * plantilla del rol (rol_permisos) como en los usuarios ya creados
     * (permisos_usuarios).
     */
    public function up(): void
    {
        $codigo = 'export-productos';
        $idPermiso = DB::table('permisos')->where('codigo', $codigo)->value('id');
        if (!$idPermiso) {
            $idPermiso = DB::table('permisos')->insertGetId([
                'codigo' => $codigo, 'nombre' => 'Exportar productos', 'grupo' => 'productos',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        $otrosRolesIds = DB::table('roles')
            ->when($rolUsuarioId, fn($q) => $q->where('id', '!=', $rolUsuarioId))
            ->pluck('id');

        foreach ($otrosRolesIds as $idRol) {
            $existe = DB::table('rol_permisos')->where('id_rol', $idRol)->where('id_permiso', $idPermiso)->exists();
            if (!$existe) {
                DB::table('rol_permisos')->insert(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
            }
        }

        $idsUsuarios = DB::table('users')
            ->when($rolUsuarioId, fn($q) => $q->where(fn($qq) => $qq->whereNull('id_rol')->orWhere('id_rol', '!=', $rolUsuarioId)))
            ->pluck('nro_usu');

        $tienenYa = DB::table('permisos_usuarios')->where('id_permiso', $idPermiso)->pluck('id_usuario');
        $faltantes = $idsUsuarios->diff($tienenYa);

        $filas = $faltantes->map(fn($idUsuario) => [
            'id_usuario' => $idUsuario, 'id_permiso' => $idPermiso, 'created_at' => now(), 'updated_at' => now(),
        ])->all();
        if (!empty($filas)) {
            DB::table('permisos_usuarios')->insert($filas);
        }
    }

    public function down(): void
    {
        $id = DB::table('permisos')->where('codigo', 'export-productos')->value('id');
        if (!$id) return;
        DB::table('permisos_usuarios')->where('id_permiso', $id)->delete();
        DB::table('rol_permisos')->where('id_permiso', $id)->delete();
        DB::table('permisos')->where('id', $id)->delete();
    }
};
