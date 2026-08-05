<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Caja" y "Movimientos" (stock) no tenían permisos propios: reutilizaban
     * los de Compras (list/create/update-compras) y Productos (list/update-
     * productos) respectivamente. Esto crea sus propios permisos y se los
     * otorga a todo usuario que ya tenga el permiso genérico equivalente —
     * nadie pierde acceso a lo que ya podía hacer.
     */
    private array $mapa = [
        // nuevo permiso        => permiso genérico que lo otorgaba hasta ahora
        'list-caja'           => 'list-compras',
        'create-caja'         => 'create-compras',
        'update-caja'         => 'update-compras',
        'list-movimientos'    => 'list-productos',
        'create-movimientos'  => 'update-productos',
        'delete-movimientos'  => 'update-productos',
    ];

    private array $nombres = [
        'list-caja'          => 'Ver estado de caja',
        'create-caja'        => 'Abrir caja / registrar movimiento',
        'update-caja'        => 'Cerrar caja',
        'list-movimientos'   => 'Listar movimientos de stock',
        'create-movimientos' => 'Registrar ajuste de stock',
        'delete-movimientos' => 'Eliminar movimiento de stock',
    ];

    private array $grupos = [
        'list-caja'          => 'caja',
        'create-caja'        => 'caja',
        'update-caja'        => 'caja',
        'list-movimientos'   => 'movimientos',
        'create-movimientos' => 'movimientos',
        'delete-movimientos' => 'movimientos',
    ];

    public function up(): void
    {
        foreach ($this->mapa as $nuevoCodigo => $codigoGenerico) {
            $nuevoId = DB::table('permisos')->where('codigo', $nuevoCodigo)->value('id');

            if (!$nuevoId) {
                $nuevoId = DB::table('permisos')->insertGetId([
                    'codigo'     => $nuevoCodigo,
                    'nombre'     => $this->nombres[$nuevoCodigo],
                    'grupo'      => $this->grupos[$nuevoCodigo],
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

    /**
     * Reverse the migrations.
     */
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
