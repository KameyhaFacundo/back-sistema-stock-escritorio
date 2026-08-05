<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "list-historial-caja" y se lo otorga a todo usuario que
     * ya tenga "list-compras" — hasta ahora el historial de caja (que muestra
     * el arqueo y las diferencias de TODOS los cajeros) quedaba visible con
     * ese permiso genérico de compras; con esto queda separado como su propio
     * permiso, sin sacarle acceso a quien ya podía verlo.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'list-historial-caja')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'list-historial-caja',
                'nombre'     => 'Ver historial de caja',
                'grupo'      => 'caja',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $listComprasId = DB::table('permisos')->where('codigo', 'list-compras')->value('id');
        if (!$listComprasId) {
            return;
        }

        $usuariosConListCompras = DB::table('permisos_usuarios')
            ->where('id_permiso', $listComprasId)
            ->pluck('id_usuario');

        $usuariosConHistorial = DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->pluck('id_usuario');

        $faltantes = $usuariosConListCompras->diff($usuariosConHistorial);

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'list-historial-caja')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
