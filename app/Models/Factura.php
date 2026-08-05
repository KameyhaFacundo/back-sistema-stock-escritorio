<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;

class Factura extends Model
{
    use HasTenant;

    protected $table = 'facturas';

    // numero_completo no estaba en $appends pese a que el frontend ya lo
    // leía (Home.jsx) — nunca llegaba en la respuesta real, quedaba pisado
    // por su fallback `#${numero}`. qr_url es nuevo: el QR que exige ARCA
    // (RG 4291) en todo comprobante electrónico, se arma solo, no hace
    // falta pedirlo aparte.
    protected $appends = ['numero_completo', 'qr_url'];

    // items es solo el detalle que EmitirFacturaJob necesita para terminar
    // de emitir una factura pendiente — dato interno de ARCA, no algo que
    // el front tenga que ver (la venta ya tiene sus propias líneas).
    protected $hidden = ['items'];

    protected $fillable = [
        'empresa_id',
        'id_venta',
        'id_comprobante_asociado',
        'id_devolucion_venta',
        'id_usuario',
        'tipo_comprobante',
        'punto_venta',
        'numero',
        'cae',
        'vencimiento_cae',
        'fecha',
        'total',
        'neto',
        'iva',
        'tipo_documento',
        'numero_documento',
        'cliente_nombre',
        'estado',
        'error_mensaje',
        'items',
    ];

    protected $casts = [
        'items' => 'array',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario', 'nro_usu');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /**
     * Solo tiene valor cuando esta fila ES una nota de crédito — apunta a la
     * factura original que credita.
     */
    public function comprobanteAsociado()
    {
        return $this->belongsTo(Factura::class, 'id_comprobante_asociado');
    }

    /**
     * Notas de crédito emitidas contra ESTA factura (si es una factura, no
     * una NC) — usado para calcular cuánto ya se acreditó antes de emitir una
     * NC nueva (ver FacturaController::emitirNotaCredito()).
     */
    public function notasCredito()
    {
        return $this->hasMany(Factura::class, 'id_comprobante_asociado');
    }

    /**
     * Solo tiene valor cuando esta fila ES una nota de crédito emitida a
     * partir de una devolución parcial de venta — permite mostrar el detalle
     * de esa devolución (fecha, monto, motivo) junto al comprobante.
     */
    public function devolucionVenta()
    {
        return $this->belongsTo(DevolucionVenta::class, 'id_devolucion_venta');
    }

    public function getNumeroCompletoAttribute(): ?string
    {
        // Una factura pendiente (ver EmitirFacturaJob) todavía no tiene
        // número real — ARCA es quien lo asigna, recién al resolverse.
        if ($this->numero === null) {
            return null;
        }

        // AFIP/ARCA siempre exige el punto de venta con 5 dígitos (no 4) en
        // cualquier formato visible de comprobante.
        return str_pad($this->punto_venta, 5, '0', STR_PAD_LEFT) . '-'
            . str_pad($this->numero, 8, '0', STR_PAD_LEFT);
    }

    /**
     * URL del código QR que ARCA exige en todo comprobante electrónico
     * (RG 4291) — sin esto, un comprobante impreso con CAE real no es
     * válido como factura aunque el CAE haya sido aprobado de verdad.
     * Se genera también en modo prueba (CAE ficticio, sin certificado ARCA
     * real cargado) para poder probar/demostrar el flujo de impresión
     * completo — igual que hace afipsdk/front-comercial, que arma el QR
     * client-side sin validar nada contra ARCA. Escanearlo con un CAE de
     * prueba no va a encontrar el comprobante del lado de ARCA, pero eso ya
     * lo distingue el propio CAE ficticio, no el QR.
     */
    public function getQrUrlAttribute(): ?string
    {
        if (!$this->cae) {
            return null;
        }

        $cuit = preg_replace('/\D/', '', $this->empresa?->cuit ?? '');
        if (!$cuit) {
            return null;
        }

        $payload = [
            'ver'        => 1,
            'fecha'      => date('Y-m-d', strtotime((string) $this->fecha)),
            'cuit'       => (int) $cuit,
            'ptoVta'     => (int) $this->punto_venta,
            'tipoCmp'    => (int) $this->tipo_comprobante,
            'nroCmp'     => (int) $this->numero,
            'importe'    => (float) $this->total,
            'moneda'     => 'PES',
            'ctz'        => 1,
            'tipoDocRec' => (int) $this->tipo_documento,
            'nroDocRec'  => (int) preg_replace('/\D/', '', (string) $this->numero_documento) ?: 0,
            'tipoCodAut' => 'E',
            'codAut'     => (int) $this->cae,
        ];

        return 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($payload));
    }
}
