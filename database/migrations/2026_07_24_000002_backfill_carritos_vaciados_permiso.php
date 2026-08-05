<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "list-carritos-vaciados" y se lo otorga a todo usuario
     * que ya tenga "view-configuracion" — es una pantalla de auditoría
     * pensada para dueño/gerente, no para cualquier vendedor, así que se
     * ancla al mismo grupo que ya tiene acceso a la configuración sensible
     * del negocio.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'list-carritos-vaciados')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'list-carritos-vaciados',
                'nombre'     => 'Ver carritos vaciados (auditoría)',
                'grupo'      => 'auditoria',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $anclaId = DB::table('permisos')->where('codigo', 'view-configuracion')->value('id');
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
        $permisoId = DB::table('permisos')->where('codigo', 'list-carritos-vaciados')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
