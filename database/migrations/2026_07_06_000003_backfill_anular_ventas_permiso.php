<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "anular-ventas" y se lo otorga a todo usuario que ya
     * tenga "change-status-ventas" — hasta ahora la anulación de ventas usaba
     * ese permiso genérico; con esto queda separado como su propio permiso,
     * sin sacarle acceso a quien ya podía anular ventas.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'anular-ventas')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'anular-ventas',
                'nombre'     => 'Anular venta',
                'grupo'      => 'ventas',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $changeStatusId = DB::table('permisos')->where('codigo', 'change-status-ventas')->value('id');
        if (!$changeStatusId) {
            return;
        }

        $usuariosConChangeStatus = DB::table('permisos_usuarios')
            ->where('id_permiso', $changeStatusId)
            ->pluck('id_usuario');

        $usuariosConAnular = DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->pluck('id_usuario');

        $faltantes = $usuariosConChangeStatus->diff($usuariosConAnular);

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
        $permisoId = DB::table('permisos')->where('codigo', 'anular-ventas')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
