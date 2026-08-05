<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Talle;

/**
 * Solo aplica a productos con `tiene_variantes` — que hoy solo pueden crear
 * empresas con tipo 'ferret'... digo, 'indument' (ver CreateProductoRequest,
 * el único punto de entrada que decide esto). El resto de la app no necesita
 * saber que esta función existe.
 */
class VarianteProductoService
{
    /**
     * Crea las variantes que le falten a un producto madre según el grupo de
     * talles que tiene asignado — nunca borra ni recrea una variante que ya
     * existe (a diferencia de los combos, cada variante tiene su propio
     * stock/historial de ventas — un reemplazo total los destruiría). Sacar un
     * talle puntual de un producto es una acción explícita aparte, no un
     * efecto secundario de guardar el padre.
     *
     * Sí actualiza en las variantes YA existentes los campos que no tiene
     * sentido que diverjan del padre (nombre, precio, costo, categoría,
     * proveedor, foto, activo) — sin esto, cambiar el precio de "Remera
     * básica" nunca se reflejaría en las variantes que ya se habían generado
     * antes de ese cambio.
     */
    public function sincronizar(Producto $padre): void
    {
        if (!$padre->tiene_variantes || !$padre->id_grupo_talle) {
            return;
        }

        // load() (no loadMissing()): este método puede llamarse más de una vez
        // sobre la misma instancia dentro de un mismo request (ver
        // propagarNuevoTalle()) — confiar en loadMissing() dejaría la relación
        // vieja cacheada de la primera llamada, haciendo que la segunda vea una
        // lista de variantes desactualizada y hasta cree duplicados.
        $padre->load('grupoTalle.talles', 'variantes');

        $grupo = $padre->grupoTalle;
        // HasTenant ya scopea todas las queries por empresa_id, pero no confiamos
        // solo en la FK para algo tan sensible como cruzar datos entre tenants.
        if (!$grupo || $grupo->empresa_id !== $padre->empresa_id) {
            return;
        }

        $camposCompartidos = [
            'producto'     => $padre->producto,
            'precio'       => $padre->precio,
            'costo'        => $padre->costo,
            'estado'       => $padre->estado,
            'id_categoria' => $padre->id_categoria,
            'id_proveedor' => $padre->id_proveedor,
            'imagen_path'  => $padre->imagen_path,
        ];

        foreach ($padre->variantes as $variante) {
            $variante->update($camposCompartidos);
        }

        $tallesExistentes = $padre->variantes->pluck('id_talle')->filter()->all();

        foreach ($grupo->talles as $talle) {
            if (in_array($talle->id, $tallesExistentes, true)) {
                continue;
            }

            Producto::create([
                'empresa_id'        => $padre->empresa_id,
                'codigo'            => $this->codigoVariante($padre, $talle),
                'id_producto_padre' => $padre->id,
                'id_talle'          => $talle->id,
                ...$camposCompartidos,
            ]);
        }
    }

    /**
     * Cuando se agrega un talle a un grupo que ya usan productos madre
     * existentes, cada uno de esos productos necesita la variante nueva — si
     * solo sincronizáramos al guardar el producto madre, un local con muchos
     * productos usando la misma curva nunca la vería reflejada en la práctica
     * (ver TallesController::store()).
     */
    public function propagarNuevoTalle(Talle $talle): void
    {
        $padres = Producto::where('id_grupo_talle', $talle->id_grupo_talle)
            ->where('tiene_variantes', true)
            ->get();

        foreach ($padres as $padre) {
            $this->sincronizar($padre);
        }
    }

    private function codigoVariante(Producto $padre, Talle $talle): string
    {
        $base   = $padre->codigo ?: ('PROD' . $padre->id);
        $sufijo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $talle->valor)) ?: $talle->id;
        $codigo = "{$base}-{$sufijo}";

        // Único en toda la tabla, igual que ya exige CreateProductoRequest para
        // 'codigo' — incluye soft-deleted porque la regla `unique` de Laravel
        // también los cuenta como ocupados por default.
        $intento = $codigo;
        $i = 2;
        while (Producto::withTrashed()->where('codigo', $intento)->exists()) {
            $intento = "{$codigo}-{$i}";
            $i++;
        }

        return $intento;
    }
}
