<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "devolver-compras" y se lo otorga a todo usuario que ya
     * tenga "change-status-compras" (el que hoy permite anular una compra
     * entera) — mismo criterio que backfill_devolver_ventas_permiso.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'devolver-compras')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'devolver-compras',
                'nombre'     => 'Devolver mercadería de una compra',
                'grupo'      => 'compras',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $changeStatusId = DB::table('permisos')->where('codigo', 'change-status-compras')->value('id');
        if (!$changeStatusId) {
            return;
        }

        $usuariosConChangeStatus = DB::table('permisos_usuarios')
            ->where('id_permiso', $changeStatusId)
            ->pluck('id_usuario');

        $usuariosConDevolver = DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->pluck('id_usuario');

        $faltantes = $usuariosConChangeStatus->diff($usuariosConDevolver);

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
        $permisoId = DB::table('permisos')->where('codigo', 'devolver-compras')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
