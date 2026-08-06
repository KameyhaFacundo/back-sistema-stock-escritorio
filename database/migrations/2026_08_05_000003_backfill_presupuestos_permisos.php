<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Otorga los permisos nuevos de presupuestos a todo usuario que ya
     * pueda crear ventas ("create-ventas" como ancla) — un presupuesto es,
     * en la práctica, el mismo flujo de venta con un paso previo, así que
     * quien ya podía facturar en el POS no debería quedar afuera de esto
     * en una instalación existente (PermisoSeeder/RolSeeder/UsuarioSeeder
     * solo corren solos en una instalación nueva — ver ensureInitialized()
     * en escritorio-launcher/electron/backend.js).
     */
    public function up(): void
    {
        $nuevos = [
            ['codigo' => 'list-presupuestos',   'nombre' => 'Listar presupuestos',      'grupo' => 'presupuestos'],
            ['codigo' => 'view-presupuestos',   'nombre' => 'Ver presupuesto',          'grupo' => 'presupuestos'],
            ['codigo' => 'create-presupuestos', 'nombre' => 'Crear presupuesto',        'grupo' => 'presupuestos'],
            ['codigo' => 'update-presupuestos', 'nombre' => 'Actualizar presupuesto',   'grupo' => 'presupuestos'],
            ['codigo' => 'delete-presupuestos', 'nombre' => 'Eliminar presupuesto',     'grupo' => 'presupuestos'],
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

        $anclaId = DB::table('permisos')->where('codigo', 'create-ventas')->value('id');
        if (!$anclaId) {
            return;
        }

        $usuariosConAncla = DB::table('permisos_usuarios')->where('id_permiso', $anclaId)->pluck('id_usuario');

        foreach ($idsNuevos as $idPermiso) {
            $usuariosConNuevo = DB::table('permisos_usuarios')->where('id_permiso', $idPermiso)->pluck('id_usuario');
            $faltantes = $usuariosConAncla->diff($usuariosConNuevo);

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
        $codigos = ['list-presupuestos', 'view-presupuestos', 'create-presupuestos', 'update-presupuestos', 'delete-presupuestos'];
        $ids = DB::table('permisos')->whereIn('codigo', $codigos)->pluck('id');
        DB::table('permisos_usuarios')->whereIn('id_permiso', $ids)->delete();
        DB::table('permisos')->whereIn('id', $ids)->delete();
    }
};
