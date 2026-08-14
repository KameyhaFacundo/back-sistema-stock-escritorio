<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Importar/Exportar clientes no tenían permiso propio — los botones del
     * front no chequeaban nada, así que cualquiera con acceso a Clientes
     * (incluido el rol "usuario") podía usarlos. Se crean los dos permisos
     * nuevos y se reparten a todos los roles MENOS "usuario" (import/export
     * masivo queda reservado a roles de más confianza) — tanto en la
     * PLANTILLA del rol (rol_permisos, para altas nuevas) como en los
     * usuarios ya creados (permisos_usuarios), ver mismo criterio que
     * 2026_08_05_000003_backfill_presupuestos_permisos.php.
     */
    public function up(): void
    {
        $nuevos = [
            ['codigo' => 'import-clientes', 'nombre' => 'Importar clientes', 'grupo' => 'clientes'],
            ['codigo' => 'export-clientes', 'nombre' => 'Exportar clientes', 'grupo' => 'clientes'],
        ];

        $idsNuevos = [];
        foreach ($nuevos as $permiso) {
            $id = DB::table('permisos')->where('codigo', $permiso['codigo'])->value('id');
            if (!$id) {
                $id = DB::table('permisos')->insertGetId(array_merge($permiso, [
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            $idsNuevos[] = $id;
        }

        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        $otrosRolesIds = DB::table('roles')
            ->when($rolUsuarioId, fn($q) => $q->where('id', '!=', $rolUsuarioId))
            ->pluck('id');

        foreach ($idsNuevos as $idPermiso) {
            foreach ($otrosRolesIds as $idRol) {
                $existe = DB::table('rol_permisos')
                    ->where('id_rol', $idRol)->where('id_permiso', $idPermiso)->exists();
                if (!$existe) {
                    DB::table('rol_permisos')->insert(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
                }
            }
        }

        $idsUsuarios = DB::table('users')
            ->when($rolUsuarioId, fn($q) => $q->where(fn($qq) => $qq->whereNull('id_rol')->orWhere('id_rol', '!=', $rolUsuarioId)))
            ->pluck('nro_usu');

        foreach ($idsNuevos as $idPermiso) {
            $tienenYa = DB::table('permisos_usuarios')->where('id_permiso', $idPermiso)->pluck('id_usuario');
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
        $codigos = ['import-clientes', 'export-clientes'];
        $ids = DB::table('permisos')->whereIn('codigo', $codigos)->pluck('id');
        DB::table('permisos_usuarios')->whereIn('id_permiso', $ids)->delete();
        DB::table('rol_permisos')->whereIn('id_permiso', $ids)->delete();
        DB::table('permisos')->whereIn('id', $ids)->delete();
    }
};
