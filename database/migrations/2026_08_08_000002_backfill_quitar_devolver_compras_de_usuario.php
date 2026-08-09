<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * devolver-compras salió del rol básico "Usuario" (ver RolSeeder) — una
     * devolución de compra baja el stock de forma "legítima" y es la
     * tapadera perfecta para encubrir un robo (mismo criterio que ya
     * separaba anular-ventas/aplicar-descuento-ventas del vendedor común).
     *
     * El rol NO otorga permisos por sí solo — lo que decide el acceso real
     * son los permisos_usuarios ya sincronizados en cada usuario (ver
     * UsuarioSeeder/AuthController::register), así que cambiar el seeder
     * de acá para arriba no le saca nada a nadie que ya esté creado. Esto
     * se lo quita a mano a cualquier usuario con el rol "usuario" que ya
     * lo tuviera. Gerente/admin no se tocan — ahí sigue siendo intencional.
     */
    public function up(): void
    {
        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        $permisoId    = DB::table('permisos')->where('codigo', 'devolver-compras')->value('id');

        if (!$rolUsuarioId || !$permisoId) {
            return;
        }

        $idsUsuarios = DB::table('users')->where('id_rol', $rolUsuarioId)->pluck('nro_usu');

        DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->whereIn('id_usuario', $idsUsuarios)
            ->delete();
    }

    public function down(): void
    {
        $rolUsuarioId = DB::table('roles')->where('codigo', 'usuario')->value('id');
        $permisoId    = DB::table('permisos')->where('codigo', 'devolver-compras')->value('id');

        if (!$rolUsuarioId || !$permisoId) {
            return;
        }

        $idsUsuarios = DB::table('users')->where('id_rol', $rolUsuarioId)->pluck('nro_usu');

        $filas = $idsUsuarios->map(fn($idUsuario) => [
            'id_usuario' => $idUsuario,
            'id_permiso' => $permisoId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (!empty($filas)) {
            DB::table('permisos_usuarios')->insert($filas);
        }
    }
};
