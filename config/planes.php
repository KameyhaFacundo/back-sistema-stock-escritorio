<?php

// Límites por plan — deben coincidir con lo que se promete en
// front-sistema-stock/src/pages/Planes/Planes.jsx y Landing.jsx.
// 'free' es el trial de 7 días antes de elegir un plan pago: usa los
// límites del plan más chico (Esencial) para no regalar más de lo que
// se cobra después.
return [
    'limites' => [
        // 'sucursales' en null = sin límite (mismo criterio que 'productos'/
        // 'usuarios' en ChecksPlanLimits::limitePlanExcedido).
        'free'     => ['productos' => 3500,  'usuarios' => 2, 'sucursales' => 1],
        'esencial' => ['productos' => 3500,  'usuarios' => 2, 'sucursales' => 1],
        'pro'      => ['productos' => 10000, 'usuarios' => 5, 'sucursales' => 2],
        'ia'       => ['productos' => null,  'usuarios' => null, 'sucursales' => null],
    ],

    // Funciones on/off por plan (no son un contador, son "la tenés o no la
    // tenés"): catálogo online (y la foto de producto que lo alimenta) y
    // cobro con Mercado Pago Point/QR desde Pro; facturación electrónica ARCA
    // en todos los planes pagos (incluido Esencial); todo lo que usa IA de
    // verdad (sugerencias, generar/componer imagen con IA, asistente interno,
    // escaneo de facturas) solo en el plan IA.
    'features' => [
        'free'     => ['catalogo' => false, 'ia' => false, 'cobros' => false, 'facturacion' => false, 'etiquetas' => false],
        'esencial' => ['catalogo' => false, 'ia' => false, 'cobros' => false, 'facturacion' => true,  'etiquetas' => false],
        'pro'      => ['catalogo' => true,  'ia' => false, 'cobros' => true,  'facturacion' => true,  'etiquetas' => true],
        'ia'       => ['catalogo' => true,  'ia' => true,  'cobros' => true,  'facturacion' => true,  'etiquetas' => true],
    ],

    // Packs prepagos de facturación electrónica — add-on independiente del
    // plan, pago único sin vencimiento (se consumen de a uno por factura
    // emitida, ver Empresa::facturas_disponibles). Solo se pueden comprar si
    // el plan ya incluye la feature 'facturacion' (ver FacturacionPackController).
    'packs_facturacion' => [
        '500'  => ['cantidad' => 500,  'precio' => 10000],
        '1000' => ['cantidad' => 1000, 'precio' => 20000],
        '2000' => ['cantidad' => 2000, 'precio' => 40000],
    ],

    // Bono único de facturas gratis al activar cada plan por primera vez (ver
    // Empresa::otorgarBonoFacturas()) — no se renueva mes a mes, es un regalo
    // de una sola vez por plan. Si después se necesitan más, se compran con
    // 'packs_facturacion' de arriba.
    'facturas_gratis_bono' => [
        'esencial' => 100,
        'pro'      => 200,
        'ia'       => 400,
    ],
];
