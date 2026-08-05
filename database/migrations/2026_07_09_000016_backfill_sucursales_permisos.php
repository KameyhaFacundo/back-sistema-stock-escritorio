<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gestionar sucursales es, conceptualmente, parte de la configuración del
     * negocio — se le otorga a todo usuario que ya tenga permiso de ver/editar
     * la configuración, mismo patrón que backfill_caja_movimientos_permisos.
     */
    private array $mapa = [
        'list-sucursales'   => 'view-configuracion',
        'create-sucursales' => 'update-configuracion',
        'update-sucursales' => 'update-configuracion',
        'delete-sucursales' => 'update-configuracion',
    ];

    private array $nombres = [
        'list-sucursales'   => 'Listar sucursales',
        'create-sucursales' => 'Crear sucursal',
        'update-sucursales' => 'Actualizar sucursal',
        'delete-sucursales' => 'Eliminar sucursal',
    ];

    public function up(): void
    {
        foreach ($this->mapa as $nuevoCodigo => $codigoGenerico) {
            $nuevoId = DB::table('permisos')->where('codigo', $nuevoCodigo)->value('id');

            if (!$nuevoId) {
                $nuevoId = DB::table('permisos')->insertGetId([
                    'codigo'     => $nuevoCodigo,
                    'nombre'     => $this->nombres[$nuevoCodigo],
                    'grupo'      => 'sucursales',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $genericoId = DB::table('permisos')->where('codigo', $codigoGenerico)->value('id');
            if (!$genericoId) {
                continue;
            }

            $usuariosConGenerico = DB::table('permisos_usuarios')
                ->where('id_permiso', $genericoId)
                ->pluck('id_usuario');

            $usuariosConNuevo = DB::table('permisos_usuarios')
                ->where('id_permiso', $nuevoId)
                ->pluck('id_usuario');

            $faltantes = $usuariosConGenerico->diff($usuariosConNuevo);

            $filas = $faltantes->map(fn($idUsuario) => [
                'id_usuario' => $idUsuario,
                'id_permiso' => $nuevoId,
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
        foreach (array_keys($this->mapa) as $codigo) {
            $id = DB::table('permisos')->where('codigo', $codigo)->value('id');
            if ($id) {
                DB::table('permisos_usuarios')->where('id_permiso', $id)->delete();
                DB::table('permisos')->where('id', $id)->delete();
            }
        }
    }
};
