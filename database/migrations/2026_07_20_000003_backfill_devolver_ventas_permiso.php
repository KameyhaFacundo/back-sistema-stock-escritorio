<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "devolver-ventas" y se lo otorga a todo usuario que ya
     * tenga "anular-ventas" — quien ya está habilitado para anular una venta
     * entera (más sensible) razonablemente también debería poder registrar
     * una devolución parcial (menos sensible), sin que el alta de este
     * permiso nuevo deje a nadie afuera por default.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'devolver-ventas')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'devolver-ventas',
                'nombre'     => 'Devolver productos de una venta',
                'grupo'      => 'ventas',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $anularId = DB::table('permisos')->where('codigo', 'anular-ventas')->value('id');
        if (!$anularId) {
            return;
        }

        $usuariosConAnular = DB::table('permisos_usuarios')
            ->where('id_permiso', $anularId)
            ->pluck('id_usuario');

        $usuariosConDevolver = DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->pluck('id_usuario');

        $faltantes = $usuariosConAnular->diff($usuariosConDevolver);

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
        $permisoId = DB::table('permisos')->where('codigo', 'devolver-ventas')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
