<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "ver-montos-caja" (monto inicial, ventas efectivo,
     * ingresos/egresos manuales) y se lo otorga a todo usuario que ya tenga
     * "list-caja" — nadie deja de ver lo que ya veía; de acá en más, un
     * admin puede sacarle este permiso puntual a un usuario para ocultarle
     * esos montos sin sacarle el acceso a Caja entero.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'ver-montos-caja')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'ver-montos-caja',
                'nombre'     => 'Ver montos de caja (monto inicial, ventas efectivo, ingresos/egresos manuales)',
                'grupo'      => 'caja',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $anclaId = DB::table('permisos')->where('codigo', 'list-caja')->value('id');
        if (!$anclaId) {
            return;
        }

        $usuariosConAncla = DB::table('permisos_usuarios')
            ->where('id_permiso', $anclaId)
            ->pluck('id_usuario');

        $usuariosConNuevo = DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->pluck('id_usuario');

        $faltantes = $usuariosConAncla->diff($usuariosConNuevo);

        $filas = $faltantes->map(fn($idUsuario) => [
            'id_usuario' => $idUsuario,
            'id_permiso' => $permisoId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (!empty($filas)) {
            DB::table('permisos_usuarios')->insert($filas);
        }
    }

    public function down(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'ver-montos-caja')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
