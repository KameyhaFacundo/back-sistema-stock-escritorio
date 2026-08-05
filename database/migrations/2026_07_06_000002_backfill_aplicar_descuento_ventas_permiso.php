<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea el permiso "aplicar-descuento-ventas" y se lo otorga a todo usuario
     * que ya tenga "create-ventas" — así el nuevo permiso no le bloquea a nadie
     * una funcionalidad (aplicar descuentos) que hasta ahora era libre para
     * cualquiera que pudiera vender. Queda a criterio del admin restringirlo
     * después por usuario desde la pantalla de Usuarios.
     */
    public function up(): void
    {
        $permisoId = DB::table('permisos')->where('codigo', 'aplicar-descuento-ventas')->value('id');

        if (!$permisoId) {
            $permisoId = DB::table('permisos')->insertGetId([
                'codigo'     => 'aplicar-descuento-ventas',
                'nombre'     => 'Aplicar descuento en venta',
                'grupo'      => 'ventas',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $crearVentasId = DB::table('permisos')->where('codigo', 'create-ventas')->value('id');
        if (!$crearVentasId) {
            return;
        }

        $usuariosConVentas = DB::table('permisos_usuarios')
            ->where('id_permiso', $crearVentasId)
            ->pluck('id_usuario');

        $usuariosConDescuento = DB::table('permisos_usuarios')
            ->where('id_permiso', $permisoId)
            ->pluck('id_usuario');

        $faltantes = $usuariosConVentas->diff($usuariosConDescuento);

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
        $permisoId = DB::table('permisos')->where('codigo', 'aplicar-descuento-ventas')->value('id');
        if ($permisoId) {
            DB::table('permisos_usuarios')->where('id_permiso', $permisoId)->delete();
            DB::table('permisos')->where('id', $permisoId)->delete();
        }
    }
};
