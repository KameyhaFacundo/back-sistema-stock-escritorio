<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PagoSuscripcion;

class Empresa extends Model
{
    protected $table = 'empresas';

    protected $appends = ['logo_url'];

    // El cert/key/passphrase de ARCA nunca deben salir en una respuesta JSON
    // (por ejemplo el objeto `empresa` que viaja en /me al loguearse) — el
    // cast 'encrypted' los desencripta automáticamente al leerlos, así que
    // sin esto viajarían en texto plano a cualquier response que serialice
    // la empresa. Usar Empresa::arcaConfigurada() para saber si están
    // cargados, nunca leer estas columnas fuera de ArcaService.
    protected $hidden = ['arca_cert', 'arca_key', 'arca_passphrase'];

    protected $fillable = [
        'nombre',
        'logo_path',
        'cuit',
        'tipo',
        'pais',
        'direccion',
        'whatsapp',
        'condicion_fiscal',
        'iibb',
        'inicio_actividad',
        'email',
        'plan',
        'trial_ends_at',
        'arca',
        'pos',
        'suspendida',
        'puntos_activo',
        'puntos_por_moneda',
        'puntos_valor_pesos',
        'catalogo_slug',
        'catalogo_activo',
        'arqueo_metodos_visibles',
        'arca_cert',
        'arca_key',
        'arca_passphrase',
        'arca_punto_venta',
        'arca_homologacion',
        'facturas_disponibles',
    ];

    protected $casts = [
        'arca'              => 'boolean',
        'suspendida'        => 'boolean',
        'trial_ends_at'     => 'datetime',
        'inicio_actividad'  => 'date:Y-m-d',
        'puntos_activo'     => 'boolean',
        'catalogo_activo'   => 'boolean',
        'arqueo_metodos_visibles' => 'array',
        'arca_cert'         => 'encrypted',
        'arca_key'          => 'encrypted',
        'arca_passphrase'   => 'encrypted',
        'arca_homologacion' => 'boolean',
        'facturas_disponibles' => 'integer',
        'facturas_bono_otorgado' => 'array',
    ];

    /**
     * ¿Esta empresa cargó su propio certificado de ARCA? Punto único de
     * verdad para "puede facturar" — nunca leer arca_cert/arca_key sueltos
     * fuera de ArcaService.
     */
    public function arcaConfigurada(): bool
    {
        return (bool) ($this->cuit && $this->arca_cert && $this->arca_key && $this->arca_punto_venta);
    }

    /**
     * Bono único de facturas gratis al activar un plan por primera vez (ver
     * config/planes.php 'facturas_gratis_bono') — se llama cada vez que se
     * fija el plan de la empresa (webhook de Mercado Pago, alta manual desde
     * SuperAdmin), sea o no la primera vez, porque la propia lista
     * 'facturas_bono_otorgado' evita otorgarlo dos veces para el mismo plan
     * (por ejemplo, en cada renovación mensual del mismo plan).
     */
    public function otorgarBonoFacturas(string $plan): void
    {
        $bono = config('planes.facturas_gratis_bono')[$plan] ?? 0;
        if ($bono <= 0) {
            return;
        }

        $otorgados = $this->facturas_bono_otorgado ?? [];
        if (in_array($plan, $otorgados, true)) {
            return;
        }

        $this->increment('facturas_disponibles', $bono);
        // No fillable a propósito (ver $fillable) — update() lo ignoraría en
        // silencio, hay que setearlo directo y guardar.
        $this->facturas_bono_otorgado = [...$otorgados, $plan];
        $this->save();
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * Genera (si todavía no tiene) un slug legible para la URL pública del
     * catálogo, a partir del nombre de la empresa. Le agrega un sufijo random
     * solo si hace falta para no chocar con uno ya existente.
     */
    public function asegurarCatalogoSlug(): string
    {
        if ($this->catalogo_slug) {
            return $this->catalogo_slug;
        }

        $base = Str::slug($this->nombre) ?: 'negocio';
        $slug = $base;
        $intento = 1;
        while (self::where('catalogo_slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $base . '-' . Str::random(4);
            if (++$intento > 5) {
                $slug = $base . '-' . Str::random(8);
                break;
            }
        }

        $this->update(['catalogo_slug' => $slug]);

        return $slug;
    }

    public function usuarios()
    {
        return $this->hasMany(User::class, 'empresa_id');
    }

    public function pagos()
    {
        return $this->hasMany(PagoSuscripcion::class, 'empresa_id');
    }

    public function pagosFacturacionPacks()
    {
        return $this->hasMany(PagoFacturacionPack::class, 'empresa_id');
    }

    public function mercadoPagoConexion()
    {
        return $this->hasOne(MercadoPagoConexion::class, 'empresa_id');
    }

    public function sucursales()
    {
        return $this->hasMany(Sucursal::class, 'empresa_id');
    }
}
