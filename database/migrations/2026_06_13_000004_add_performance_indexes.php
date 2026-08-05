<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Estos índices quedaron aplicados en algunas bases por un intento de
    // deploy anterior sin que la tabla `migrations` registrara esta corrida
    // (build automático de Railway reintentado) — el CREATE fallaba con
    // "Duplicate key name". Se agrega solo el que todavía no exista.
    private function indexExiste(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $row) {
                if ($row->name === $indexName) return true;
            }
            return false;
        }

        return !empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]));
    }

    private function addIndexIfMissing(string $table, string|array $columns, string $indexName): void
    {
        if ($this->indexExiste($table, $indexName)) return;

        Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
            $t->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExiste($table, $indexName)) return;

        Schema::table($table, function (Blueprint $t) use ($indexName) {
            $t->dropIndex($indexName);
        });
    }

    public function up(): void
    {
        $this->addIndexIfMissing('ventas', 'estado',       'idx_ventas_estado');
        $this->addIndexIfMissing('ventas', 'estado_pago',  'idx_ventas_estado_pago');
        $this->addIndexIfMissing('ventas', 'fecha',        'idx_ventas_fecha');
        $this->addIndexIfMissing('ventas', ['id_cliente', 'estado_pago'], 'idx_ventas_cliente_estado_pago');
        $this->addIndexIfMissing('ventas', ['empresa_id', 'fecha'], 'idx_ventas_empresa_fecha');
        $this->addIndexIfMissing('ventas', ['empresa_id', 'estado_pago'], 'idx_ventas_empresa_estado_pago');

        $this->addIndexIfMissing('compras', 'estado',       'idx_compras_estado');
        $this->addIndexIfMissing('compras', 'estado_deuda', 'idx_compras_estado_deuda');
        $this->addIndexIfMissing('compras', 'fecha',        'idx_compras_fecha');
        $this->addIndexIfMissing('compras', ['empresa_id', 'fecha'], 'idx_compras_empresa_fecha');
        $this->addIndexIfMissing('compras', ['empresa_id', 'estado_deuda'], 'idx_compras_empresa_deuda');

        $this->addIndexIfMissing('productos', 'estado',  'idx_productos_estado');
        $this->addIndexIfMissing('productos', 'codigo',  'idx_productos_codigo');
        $this->addIndexIfMissing('productos', ['empresa_id', 'estado'], 'idx_productos_empresa_estado');

        $this->addIndexIfMissing('turnos', 'estado',                 'idx_turnos_estado');
        $this->addIndexIfMissing('turnos', ['id_usuario', 'estado'], 'idx_turnos_usuario_estado');
        $this->addIndexIfMissing('turnos', ['empresa_id', 'estado'], 'idx_turnos_empresa_estado');

        $this->addIndexIfMissing('clientes', ['empresa_id', 'persona'], 'idx_clientes_empresa_persona');

        $this->addIndexIfMissing('proveedores', ['empresa_id', 'persona'], 'idx_proveedores_empresa_persona');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('ventas', 'idx_ventas_estado');
        $this->dropIndexIfExists('ventas', 'idx_ventas_estado_pago');
        $this->dropIndexIfExists('ventas', 'idx_ventas_fecha');
        $this->dropIndexIfExists('ventas', 'idx_ventas_cliente_estado_pago');
        $this->dropIndexIfExists('ventas', 'idx_ventas_empresa_fecha');
        $this->dropIndexIfExists('ventas', 'idx_ventas_empresa_estado_pago');

        $this->dropIndexIfExists('compras', 'idx_compras_estado');
        $this->dropIndexIfExists('compras', 'idx_compras_estado_deuda');
        $this->dropIndexIfExists('compras', 'idx_compras_fecha');
        $this->dropIndexIfExists('compras', 'idx_compras_empresa_fecha');
        $this->dropIndexIfExists('compras', 'idx_compras_empresa_deuda');

        $this->dropIndexIfExists('productos', 'idx_productos_estado');
        $this->dropIndexIfExists('productos', 'idx_productos_codigo');
        $this->dropIndexIfExists('productos', 'idx_productos_empresa_estado');

        $this->dropIndexIfExists('turnos', 'idx_turnos_estado');
        $this->dropIndexIfExists('turnos', 'idx_turnos_usuario_estado');
        $this->dropIndexIfExists('turnos', 'idx_turnos_empresa_estado');

        $this->dropIndexIfExists('clientes', 'idx_clientes_empresa_persona');

        $this->dropIndexIfExists('proveedores', 'idx_proveedores_empresa_persona');
    }
};
