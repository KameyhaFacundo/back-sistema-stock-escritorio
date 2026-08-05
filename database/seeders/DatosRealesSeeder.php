<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatosRealesSeeder extends Seeder
{
    public function run(): void
    {
        // ── Buscar usuario y empresa ────────────────────────────────────
        $user = DB::table('users')->where('email', 'admin@admin.com')->first();
        if (!$user) {
            $this->command->error('No se encontró el usuario admin@admin.com. Ejecutá primero DatabaseSeeder.');
            return;
        }
        $userId    = $user->nro_usu;
        $empresaId = $user->empresa_id;

        $this->command->info("Usuario encontrado: {$user->des_usu} (id={$userId}, empresa={$empresaId})");

        // El stock vive en producto_stock (por sucursal, ver migración
        // drop_stock_columns_from_productos_table) — hace falta al menos una
        // sucursal para poder cargarlo. UsuarioSeeder no crea ninguna, así que
        // se busca o se crea acá (mismo nombre que usó el backfill histórico
        // para las empresas que ya existían, "Casa Central").
        $idSucursal = DB::table('sucursales')->where('empresa_id', $empresaId)->value('id');
        if (!$idSucursal) {
            $idSucursal = DB::table('sucursales')->insertGetId([
                'empresa_id'   => $empresaId,
                'nombre'       => 'Casa Central',
                'activo'       => true,
                'es_principal' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
        if (!$user->id_sucursal) {
            DB::table('users')->where('nro_usu', $userId)->update(['id_sucursal' => $idSucursal]);
        }

        // ── Limpiar datos (excepto users y tablas de sistema) ───────────
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $ventaIds   = DB::table('ventas')->where('empresa_id', $empresaId)->pluck('id');
        $compraIds  = DB::table('compras')->where('empresa_id', $empresaId)->pluck('id');
        $productoIds= DB::table('productos')->where('empresa_id', $empresaId)->pluck('id');

        DB::table('pagos_cliente')->whereIn('id_venta', $ventaIds)->delete();
        DB::table('pagos_proveedor')->whereIn('id_compra', $compraIds)->delete();
        DB::table('lineas_ventas')->whereIn('id_venta', $ventaIds)->delete();
        DB::table('lineas_compras')->whereIn('id_compra', $compraIds)->delete();
        DB::table('ventas')->where('empresa_id', $empresaId)->delete();
        DB::table('compras')->where('empresa_id', $empresaId)->delete();
        // movimientos_stock no tiene empresa_id — borrar por id_producto
        DB::table('movimientos_stock')->whereIn('id_producto', $productoIds)->delete();
        DB::table('productos')->where('empresa_id', $empresaId)->delete();
        DB::table('categorias')->where('empresa_id', $empresaId)->delete();
        DB::table('clientes')->where('empresa_id', $empresaId)->delete();
        DB::table('proveedores')->where('empresa_id', $empresaId)->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $now = now()->toDateTimeString();

        // ═══════════════════════════════════════════════════════════════
        // CATEGORÍAS
        // ═══════════════════════════════════════════════════════════════
        $categorias = [
            'Bebidas', 'Alimentos secos', 'Lácteos y frescos', 'Limpieza',
            'Perfumería e higiene', 'Golosinas y snacks', 'Carnicería y fiambres',
            'Panadería y repostería', 'Ferretería', 'Electrónica y pilas',
            'Tabaquería', 'Verdulería',
        ];

        $catIds = [];
        foreach ($categorias as $nombre) {
            $catIds[$nombre] = DB::table('categorias')->insertGetId([
                'empresa_id' => $empresaId,
                'categoria'  => $nombre,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->command->info('Categorías creadas: ' . count($catIds));

        // ═══════════════════════════════════════════════════════════════
        // PRODUCTOS (110+)
        // ═══════════════════════════════════════════════════════════════
        $productosData = [
            // Bebidas
            ['Coca-Cola 1.5L',          'COC001', 2200,  1500,  48, 10, 'Bebidas'],
            ['Coca-Cola 2.25L',         'COC002', 2800,  1900,  36, 8,  'Bebidas'],
            ['Sprite 2.25L',            'SPR001', 2700,  1850,  24, 6,  'Bebidas'],
            ['Fanta Naranja 2.25L',     'FAN001', 2700,  1850,  20, 6,  'Bebidas'],
            ['Agua Villavicencio 1.5L', 'AGU001', 1200,   800,  60, 12, 'Bebidas'],
            ['Agua Villavicencio 500ml','AGU002',  800,   500,  80, 15, 'Bebidas'],
            ['Gatorade 500ml',          'GAT001', 1500,   950,  40, 8,  'Bebidas'],
            ['Cerveza Quilmes 1L',      'CER001', 2800,  1900,  30, 8,  'Bebidas'],
            ['Cerveza Brahma 473ml',    'CER002', 1400,   900,  48, 10, 'Bebidas'],
            ['Cerveza Stella 500ml',    'CER003', 2200,  1500,  24, 6,  'Bebidas'],
            ['Vino Toro Reservado 1L',  'VIN001', 3200,  2100,  18, 4,  'Bebidas'],
            ['Sidra La Farruca 750ml',  'SID001', 3500,  2300,  12, 4,  'Bebidas'],
            ['Jugo Cepita 1L',          'CEP001', 1800,  1100,  36, 8,  'Bebidas'],
            ['Te Lipton x20',           'TEL001',  950,   580,  30, 6,  'Bebidas'],
            ['Café La Virginia 100g',   'CAF001', 2100,  1300,  20, 5,  'Bebidas'],

            // Alimentos secos
            ['Arroz Gallo Parboil 1kg', 'ARR001', 1800,  1100,  50, 10, 'Alimentos secos'],
            ['Fideos Lucchetti 400g',   'FID001',  950,   580,  60, 12, 'Alimentos secos'],
            ['Fideos Matarazzo 500g',   'FID002',  980,   600,  45, 10, 'Alimentos secos'],
            ['Aceite Natura 900ml',     'ACE001', 3200,  2100,  30, 8,  'Alimentos secos'],
            ['Harina 000 1kg',          'HAR001',  750,   450,  40, 10, 'Alimentos secos'],
            ['Harina 0000 1kg',         'HAR002',  800,   480,  35, 10, 'Alimentos secos'],
            ['Azúcar Ledesma 1kg',      'AZU001',  900,   550,  50, 10, 'Alimentos secos'],
            ['Sal Dos Anclas 500g',     'SAL001',  380,   220,  60, 10, 'Alimentos secos'],
            ['Polenta Exquisita 500g',  'POL001',  850,   520,  25, 6,  'Alimentos secos'],
            ['Lentejas Secas 500g',     'LEN001',  980,   600,  20, 5,  'Alimentos secos'],
            ['Puré de Tomate 520g',     'PUR001',  780,   470,  40, 8,  'Alimentos secos'],
            ['Tomate Perita Arcor 400g','TOM001',  650,   390,  45, 8,  'Alimentos secos'],
            ['Mayonesa Hellmann\'s 250g','MAY001', 1400,   850,  25, 6,  'Alimentos secos'],
            ['Ketchup Heinz 380g',      'KET001', 1800,  1100,  18, 4,  'Alimentos secos'],
            ['Vinagre Dos Anclas 500ml','VIN002',  450,   270,  25, 5,  'Alimentos secos'],

            // Lácteos y frescos
            ['Leche La Serenísima 1L',  'LEC001', 1100,   680,  80, 15, 'Lácteos y frescos'],
            ['Leche Descremada 1L',     'LEC002', 1200,   750,  40, 10, 'Lácteos y frescos'],
            ['Yogur Ser Vainilla 190g', 'YOG001',  650,   380,  30, 8,  'Lácteos y frescos'],
            ['Crema de Leche 200ml',    'CRE001',  950,   580,  20, 5,  'Lácteos y frescos'],
            ['Manteca La Serenísima',   'MAN001', 1500,   900,  25, 6,  'Lácteos y frescos'],
            ['Queso Cremoso x kg',      'QUE001', 8500,  5500,  12, 3,  'Lácteos y frescos'],
            ['Queso Mozzarella x kg',   'QUE002', 9000,  5800,  10, 3,  'Lácteos y frescos'],
            ['Huevos x12',             'HUE001', 2400,  1600,  30, 8,  'Lácteos y frescos'],
            ['Ricota 250g',             'RIC001',  950,   580,  15, 4,  'Lácteos y frescos'],

            // Limpieza
            ['Detergente Magistral 500ml','DET001', 1200,  750, 40, 8, 'Limpieza'],
            ['Lavandina Ayudín 1L',     'LAV001',  950,   570,  35, 8,  'Limpieza'],
            ['Jabón Ala 800g',          'JAB001', 1800,  1100,  25, 6,  'Limpieza'],
            ['Suavizante Vivere 900ml', 'SUA001', 2100,  1300,  20, 5,  'Limpieza'],
            ['Limpiador Lysoform 500ml','LYS001', 1500,   900,  18, 4,  'Limpieza'],
            ['Papel Higiénico Elite x4','PAP001', 1800,  1100,  30, 8,  'Limpieza'],
            ['Papel de Cocina x2',      'PAP002', 1200,   720,  25, 6,  'Limpieza'],
            ['Bolsas de Residuo x20',   'BOL001',  650,   380,  40, 8,  'Limpieza'],
            ['Esponja Scotch-Brite',    'ESP001',  480,   280,  35, 8,  'Limpieza'],
            ['Trapo de Piso',           'TRA001',  850,   500,  20, 5,  'Limpieza'],

            // Perfumería e higiene
            ['Shampoo Elvive 400ml',    'SHA001', 2800,  1800,  20, 5, 'Perfumería e higiene'],
            ['Acondicionador Elvive',   'ACO001', 2800,  1800,  15, 4, 'Perfumería e higiene'],
            ['Jabón Dove 90g',          'JAB002',  850,   520,  30, 8, 'Perfumería e higiene'],
            ['Desodorante Axe 150ml',   'DES001', 2200,  1400,  20, 5, 'Perfumería e higiene'],
            ['Pasta Dental Colgate',    'PAS001', 1500,   900,  25, 6, 'Perfumería e higiene'],
            ['Cepillo Dental Colgate',  'CEP002', 1200,   720,  20, 5, 'Perfumería e higiene'],
            ['Afeitadora Gillette x2',  'AFE001', 2500,  1600,  12, 3, 'Perfumería e higiene'],
            ['Toallas Femeninas Always','TOA001', 1800,  1100,  20, 5, 'Perfumería e higiene'],

            // Golosinas y snacks
            ['Alfajor Havanna x1',      'ALF001',  850,   520,  50, 10, 'Golosinas y snacks'],
            ['Alfajor Cachafaz x1',     'ALF002',  650,   380,  60, 12, 'Golosinas y snacks'],
            ['Chocolate Milka 100g',    'CHO001', 1500,   900,  30, 8,  'Golosinas y snacks'],
            ['Chicles Beldent x1',      'CHI001',  350,   200,  80, 15, 'Golosinas y snacks'],
            ['Halls x1',                'HAL001',  250,   140,  100,20, 'Golosinas y snacks'],
            ['Papas Fritas Pringles',   'PRI001', 2200,  1400,  25, 6,  'Golosinas y snacks'],
            ['Galletitas Oreo 117g',    'ORE001', 1200,   730,  30, 8,  'Golosinas y snacks'],
            ['Galletitas Toddy 126g',   'TOD001',  950,   580,  35, 8,  'Golosinas y snacks'],
            ['Maní Salado 100g',        'MAN002',  450,   270,  40, 8,  'Golosinas y snacks'],
            ['Turrones Nucrem x1',      'NUC001',  180,   100,  60, 12, 'Golosinas y snacks'],
            ['Caramelos masticables x1','CAR001',   80,    45,  120,20, 'Golosinas y snacks'],

            // Carnicería y fiambres
            ['Salchicha Viena x10',     'SAL002', 2200,  1400,  15, 4, 'Carnicería y fiambres'],
            ['Jamón Cocido x kg',       'JAM001', 6500,  4200,  10, 3, 'Carnicería y fiambres'],
            ['Salame x kg',             'SAL003', 7500,  4900,   8, 3, 'Carnicería y fiambres'],
            ['Paleta Cocida x kg',      'PAL001', 5800,  3700,   8, 3, 'Carnicería y fiambres'],
            ['Panceta Ahumada x kg',    'PAN001', 5200,  3300,  10, 3, 'Carnicería y fiambres'],

            // Panadería y repostería
            ['Pan Lactal Bimbo 500g',   'PAN002', 1500,   900,  20, 5, 'Panadería y repostería'],
            ['Medialunas x6',           'MED001', 1800,  1100,  15, 4, 'Panadería y repostería'],
            ['Budín de Pan x1',         'BUD001', 1200,   720,  10, 3, 'Panadería y repostería'],
            ['Cacao en polvo 200g',     'CAC001', 1500,   900,  15, 4, 'Panadería y repostería'],
            ['Levadura Fleischmann',    'LEV001',  420,   250,  20, 5, 'Panadería y repostería'],

            // Ferretería
            ['Pilas AA Duracell x2',    'PIL001', 1800,  1100,  30, 6, 'Ferretería'],
            ['Pilas AAA Duracell x2',   'PIL002', 1800,  1100,  25, 6, 'Ferretería'],
            ['Cinta Scotch 18mm',       'CIN001',  950,   580,  20, 5, 'Ferretería'],
            ['Velas x10',               'VEL001',  650,   380,  15, 4, 'Ferretería'],
            ['Encendedor BIC',          'ENC001',  480,   280,  30, 8, 'Ferretería'],
            ['Focos LED 9W',            'FOC001', 1500,   900,  20, 5, 'Ferretería'],

            // Electrónica y pilas
            ['Cargador USB 5W',         'CAR002', 2800,  1700,  10, 3, 'Electrónica y pilas'],
            ['Cable USB-C 1m',          'CAB001', 1800,  1100,  15, 4, 'Electrónica y pilas'],
            ['Auriculares In-Ear',      'AUR001', 3500,  2100,   8, 2, 'Electrónica y pilas'],
            ['Pila 9V Duracell',        'PIL003', 2200,  1350,  12, 3, 'Electrónica y pilas'],

            // Tabaquería
            ['Cigarrillos Marlboro x20','CIG001', 2800,  2100,  40, 8, 'Tabaquería'],
            ['Cigarrillos Derby x20',   'CIG002', 2200,  1650,  35, 8, 'Tabaquería'],
            ['Cigarrillos L&M x20',     'CIG003', 2600,  1950,  30, 6, 'Tabaquería'],
            ['Papel de Fumar Ocb',      'PAP003',  650,   400,  20, 5, 'Tabaquería'],

            // Verdulería
            ['Papa x kg',               'VER001',  600,   350,  40, 8, 'Verdulería'],
            ['Cebolla x kg',            'VER002',  550,   320,  30, 6, 'Verdulería'],
            ['Tomate x kg',             'VER003',  800,   480,  25, 6, 'Verdulería'],
            ['Lechuga x1',              'VER004',  700,   420,  15, 4, 'Verdulería'],
            ['Zanahoria x kg',          'VER005',  480,   280,  20, 5, 'Verdulería'],
        ];

        $productosIds = [];
        foreach ($productosData as [$nombre, $codigo, $precio, $costo, $stock, $stockMin, $catNombre]) {
            $id = DB::table('productos')->insertGetId([
                'empresa_id'   => $empresaId,
                'producto'     => $nombre,
                'codigo'       => $codigo,
                'precio'       => $precio,
                'costo'        => $costo,
                'id_categoria' => $catIds[$catNombre],
                'estado'       => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            DB::table('producto_stock')->insert([
                'empresa_id'   => $empresaId,
                'id_producto'  => $id,
                'id_sucursal'  => $idSucursal,
                'stock'        => $stock,
                'stock_minimo' => $stockMin,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $productosIds[$nombre] = ['id' => $id, 'precio' => $precio, 'costo' => $costo];
        }
        $this->command->info('Productos creados: ' . count($productosIds));
        $prodList = array_values($productosIds);

        // ═══════════════════════════════════════════════════════════════
        // PROVEEDORES (10)
        // ═══════════════════════════════════════════════════════════════
        $proveedoresData = [
            ['20-11111111-1', 'Distribuidora Norte S.A.',        'Av. San Martín 1250, Tucumán',   '0381-4234567', 'ventas@disnorte.com.ar'],
            ['20-22222222-2', 'Bebidas del Centro S.R.L.',       'Ruta 9 Km 12, Córdoba',          '0351-4521890', 'pedidos@bebcentro.com.ar'],
            ['20-33333333-3', 'Lácteos Pampa S.A.',              'Calle 12 N°450, Buenos Aires',   '011-47832100', 'contacto@lactpampa.com.ar'],
            ['20-44444444-4', 'Limpieza Total Mayorista',        'Av. Belgrano 3400, Rosario',     '0341-4678912', 'ventas@limpiatotal.com.ar'],
            ['20-55555555-5', 'Golosinas y Snacks Hnos. García', 'San Juan 870, Mendoza',          '0261-4901234', 'garcia.golosinas@gmail.com'],
            ['20-66666666-6', 'Carnicería Mayorista El Toro',    'Av. Corrientes 4500, CABA',      '011-43219870', 'eltoro.may@outlook.com'],
            ['20-77777777-7', 'Tabacalera del Plata',            'Mitre 200, Buenos Aires',        '011-49871234', 'pedidos@tabplata.com.ar'],
            ['20-88888888-8', 'Ferretera Unión S.A.',            'Av. Independencia 1800, Salta',  '0387-4234567', 'ferretera.union@gmail.com'],
            ['20-99999999-9', 'Electrónica Rápida S.R.L.',       'Laprida 345, Tucumán',           '0381-4567890', 'info@electrorapida.com.ar'],
            ['27-10101010-0', 'Verduras del Campo S.C.',         'Ruta 38 Km 5, Tucumán',          '0381-4112233', 'verdcamp@gmail.com'],
        ];

        $proveedoresIds = [];
        foreach ($proveedoresData as [$cuit, $persona, $dir, $tel, $email]) {
            $id = DB::table('proveedores')->insertGetId([
                'empresa_id' => $empresaId,
                'cuit'       => $cuit,
                'persona'    => $persona,
                'direccion'  => $dir,
                'telefono'   => $tel,
                'email'      => $email,
                'estado'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $proveedoresIds[] = ['id' => $id, 'cuit' => $cuit];
        }
        $this->command->info('Proveedores creados: ' . count($proveedoresIds));

        // ═══════════════════════════════════════════════════════════════
        // CLIENTES (50)
        // ═══════════════════════════════════════════════════════════════
        $clientesData = [
            ['20-12345678-1', 'Juan García',         '3816-123456', 'juan.garcia@gmail.com',        'Av. Alem 123'],
            ['27-23456789-2', 'María López',         '3816-234567', 'maria.lopez@hotmail.com',      'Rivadavia 456'],
            ['20-34567890-3', 'Carlos Rodríguez',    '3816-345678', '',                             'San Martín 789'],
            ['27-45678901-4', 'Ana Martínez',        '3816-456789', 'ana.martinez@gmail.com',       'Belgrano 321'],
            ['20-56789012-5', 'Luis Fernández',      '3815-567890', '',                             'Mitre 654'],
            ['27-67890123-6', 'Laura González',      '3816-678901', 'lgonzalez@yahoo.com',          'Lavalle 987'],
            ['20-78901234-7', 'Roberto Pérez',       '3815-789012', '',                             'Tucumán 111'],
            ['27-89012345-8', 'Claudia Sánchez',     '3816-890123', 'claudia.sanchez@gmail.com',    'Corrientes 222'],
            ['20-90123456-9', 'Diego Ramírez',       '3815-901234', '',                             'Sarmiento 333'],
            ['27-01234567-0', 'Silvia Torres',       '3816-012345', 'storres@gmail.com',            'Pueyrredón 444'],
            ['20-11223344-5', 'Pablo Flores',        '3815-112233', '',                             'Independencia 555'],
            ['27-22334455-6', 'Patricia Moreno',     '3816-223344', 'patricia.m@gmail.com',         'San Lorenzo 666'],
            ['20-33445566-7', 'Gustavo Álvarez',     '3815-334455', '',                             'Junín 777'],
            ['27-44556677-8', 'Mónica Ruiz',         '3816-445566', 'mruiz@hotmail.com',            'Catamarca 888'],
            ['20-55667788-9', 'Fernando Jiménez',    '3815-556677', '',                             'La Rioja 999'],
            ['27-66778899-0', 'Sandra Herrera',      '3816-667788', 'sandra.h@gmail.com',           'Santiago 100'],
            ['20-77889900-1', 'Marcelo Díaz',        '3815-778899', '',                             'Córdoba 200'],
            ['27-88990011-2', 'Verónica Castro',     '3816-889900', 'vcastro@gmail.com',            'Mendoza 300'],
            ['20-99001122-3', 'Alejandro Ortiz',     '3815-990011', '',                             'Entre Ríos 400'],
            ['27-00112233-4', 'Carolina Vargas',     '3816-001122', 'carolina.v@yahoo.com',         'Chaco 500'],
            ['20-10203040-5', 'Nicolás Molina',      '3815-102030', '',                             'Formosa 600'],
            ['27-20304050-6', 'Adriana Silva',       '3816-203040', 'adriana.silva@gmail.com',      'Jujuy 700'],
            ['20-30405060-7', 'Sebastián Romero',    '3815-304050', '',                             'La Pampa 800'],
            ['27-40506070-8', 'Gabriela Guerrero',   '3816-405060', 'gguerrero@hotmail.com',        'Misiones 900'],
            ['20-50607080-9', 'Martín Navarro',      '3815-506070', '',                             'Neuquén 1000'],
            ['27-60708090-0', 'Daniela Ramos',       '3816-607080', 'daniela.r@gmail.com',          'Río Negro 50'],
            ['20-70809010-1', 'Eduardo Reyes',       '3815-708090', '',                             'Salta 150'],
            ['27-80901020-2', 'Karina Medina',       '3816-809010', 'kmedina@gmail.com',            'San Juan 250'],
            ['20-90102030-3', 'Javier Aguilar',      '3815-901020', '',                             'San Luis 350'],
            ['27-01020304-4', 'Natalia Vega',        '3816-010203', 'natalia.vega@gmail.com',       'Santa Cruz 450'],
            ['20-11223300-6', 'Ignacio Muñoz',       '3815-112230', '',                             'Santa Fe 550'],
            ['27-22334411-7', 'Luciana Parra',       '3816-223341', 'luciana.p@gmail.com',          'Tierra del Fuego 650'],
            ['20-33445522-8', 'Maximiliano Cruz',    '3815-334452', '',                             'Av. Italia 750'],
            ['27-44556633-9', 'Florencia Ríos',      '3816-445563', 'florencia.rios@outlook.com',   'Av. Mate de Luna 850'],
            ['20-55667744-0', 'Ramiro Mendoza',      '3815-556674', '',                             'Av. Roca 950'],
            ['27-66778855-1', 'Valeria Gutiérrez',   '3816-667785', 'valeria.g@gmail.com',          'Padre Montes 1050'],
            ['20-77889966-2', 'Cristian Benítez',    '3815-778896', '',                             'Las Heras 1150'],
            ['27-88990077-3', 'Romina Acosta',       '3816-889907', 'racosta@gmail.com',            'Chacabuco 1250'],
            ['20-99001188-4', 'Federico Ríos',       '3815-990018', '',                             'España 1350'],
            ['27-00112299-5', 'Cecilia Godoy',       '3816-001129', 'cecilia.godoy@gmail.com',      'Francia 1450'],
            ['20-10203041-6', 'Esteban Suárez',      '3815-102031', '',                             'Chile 1550'],
            ['27-20304052-7', 'Marta Cabrera',       '3816-203042', 'marta.cabrera@yahoo.com',      'Bolivia 1650'],
            ['20-30405063-8', 'Hernán Gallardo',     '3815-304053', '',                             'Paraguay 1750'],
            ['27-40506074-9', 'Roxana Campos',       '3816-405064', 'rxcampos@gmail.com',           'Uruguay 1850'],
            ['20-50607085-0', 'Leandro Santana',     '3815-506075', '',                             'Brasil 1950'],
            ['27-60708096-1', 'Diana Espinoza',      '3816-607086', 'diana.e@gmail.com',            'Perú 2050'],
            ['20-70809017-2', 'Claudio Pineda',      '3815-708097', '',                             'Ecuador 2150'],
            ['27-80901028-3', 'Norma Cárdenas',      '3816-809018', 'norma.c@hotmail.com',          'Colombia 2250'],
            ['20-90102039-4', 'Andrés Ibáñez',       '3815-901029', '',                             'Venezuela 2350'],
            ['27-01020305-5', 'Liliana Fuentes',     '3816-010204', 'liliana.f@gmail.com',          'Av. Perón 2450'],
        ];

        $clientesIds = [];
        foreach ($clientesData as $i => [$cuit, $persona, $tel, $email, $dir]) {
            $id = DB::table('clientes')->insertGetId([
                'empresa_id' => $empresaId,
                'cuit'       => $cuit,
                'persona'    => $persona,
                'direccion'  => $dir,
                'telefono'   => $tel,
                'email'      => $email ?: null,
                'estado'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $clientesIds[] = ['id' => $id, 'cuit' => $cuit, 'nombre' => $persona];
        }
        $this->command->info('Clientes creados: ' . count($clientesIds));

        // ═══════════════════════════════════════════════════════════════
        // COMPRAS (30)
        // ═══════════════════════════════════════════════════════════════
        $comprasData = [
            // [proveedor_idx, estado, fecha, metodo_pago, estado_deuda, productos[[prod_idx, cant, precio_costo, precio_venta]]]
            [0, 'confirmada', '2026-05-02', 'transferencia', 'pagado',   [[0,24,1500,2200],[1,12,1900,2800],[4,30,800,1200]]],
            [0, 'confirmada', '2026-05-08', 'efectivo',      'pagado',   [[5,40,500,800],[6,20,950,1500],[7,15,1900,2800]]],
            [1, 'confirmada', '2026-05-10', 'transferencia', 'pagado',   [[8,24,900,1400],[9,12,1500,2200],[10,9,2100,3200]]],
            [1, 'confirmada', '2026-05-15', 'efectivo',      'pagado',   [[11,6,2300,3500],[12,18,1100,1800],[13,15,580,950]]],
            [2, 'confirmada', '2026-05-18', 'transferencia', 'pagado',   [[30,40,680,1100],[31,20,750,1200],[33,10,580,950]]],
            [2, 'confirmada', '2026-05-20', 'efectivo',      'pagado',   [[34,12,900,1500],[35,6,5500,8500],[36,5,5800,9000]]],
            [3, 'confirmada', '2026-05-22', 'transferencia', 'pendiente',[[39,20,750,1200],[40,18,570,950],[41,12,1100,1800]]],
            [3, 'confirmada', '2026-05-25', 'efectivo',      'parcial',  [[42,10,1300,2100],[43,15,900,1500],[44,12,720,1200]]],
            [4, 'confirmada', '2026-05-28', 'transferencia', 'pagado',   [[59,24,520,850],[60,30,380,650],[61,15,900,1500]]],
            [4, 'confirmada', '2026-06-01', 'efectivo',      'pagado',   [[62,12,730,1200],[63,18,580,950],[64,20,270,450]]],
            [5, 'confirmada', '2026-06-02', 'transferencia', 'pendiente',[[70,8,1400,2200],[71,5,4200,6500],[72,4,4900,7500]]],
            [5, 'confirmada', '2026-06-03', 'efectivo',      'pagado',   [[73,4,3700,5800],[74,5,3300,5200]]],
            [6, 'confirmada', '2026-06-04', 'transferencia', 'pagado',   [[88,20,2100,2800],[89,18,1650,2200],[90,15,1950,2600]]],
            [7, 'confirmada', '2026-06-05', 'efectivo',      'pagado',   [[80,15,1100,1800],[81,12,1100,1800],[82,10,580,950]]],
            [8, 'confirmada', '2026-06-06', 'transferencia', 'pendiente',[[93,6,1700,2800],[94,8,1100,1800],[95,4,2100,3500]]],
            [9, 'confirmada', '2026-06-07', 'efectivo',      'pagado',   [[96,20,350,600],[97,15,320,550],[98,12,480,800]]],
            [0, 'confirmada', '2026-06-08', 'transferencia', 'pagado',   [[2,12,1850,2700],[3,12,1850,2700],[14,10,1300,2100]]],
            [1, 'confirmada', '2026-06-09', 'efectivo',      'pagado',   [[15,25,1100,1800],[16,30,580,950],[18,15,2100,3200]]],
            [2, 'confirmada', '2026-06-10', 'transferencia', 'parcial',  [[32,20,470,780],[33,20,580,950],[37,10,580,950]]],
            [3, 'confirmada', '2026-06-11', 'efectivo',      'pagado',   [[45,15,720,1200],[46,20,1100,1800],[47,30,380,650]]],
            [4, 'pendiente',  '2026-06-12', 'transferencia', 'pendiente',[[65,30,200,350],[66,20,140,250],[67,15,270,450]]],
            [5, 'confirmada', '2026-06-12', 'efectivo',      'pagado',   [[75,10,900,1500],[76,8,900,1500]]],
            [6, 'confirmada', '2026-06-13', 'transferencia', 'pagado',   [[91,10,400,650]]],
            [7, 'confirmada', '2026-06-13', 'efectivo',      'pendiente',[[83,8,500,850],[84,15,270,450],[85,6,900,1500]]],
            [8, 'confirmada', '2026-06-14', 'transferencia', 'pagado',   [[86,10,1100,1800],[87,12,1350,2200]]],
            [9, 'confirmada', '2026-06-14', 'efectivo',      'pagado',   [[99,8,420,700],[100,6,280,480]]],
            [0, 'pendiente',  '2026-06-15', 'transferencia', 'pendiente',[[17,20,600,980],[19,15,1100,1800],[20,10,900,1500]]],
            [1, 'confirmada', '2026-06-15', 'efectivo',      'pagado',   [[21,30,550,900],[22,25,220,380],[23,20,450,800]]],
            [2, 'confirmada', '2026-06-15', 'transferencia', 'pagado',   [[38,10,580,950],[39,10,750,1200]]],
            [3, 'confirmada', '2026-06-15', 'efectivo',      'parcial',  [[48,15,280,480],[49,10,500,850]]],
        ];

        foreach ($comprasData as $i => [$provIdx, $estado, $fecha, $metodoPago, $estadoDeuda, $lineas]) {
            $prov       = $proveedoresIds[$provIdx];
            $montoTotal = 0;
            foreach ($lineas as [$pIdx, $cant, $precioC, $precioV]) {
                $montoTotal += $cant * $precioC;
            }

            $montoPagado = match($estadoDeuda) {
                'pagado'   => $montoTotal,
                'parcial'  => round($montoTotal * 0.5),
                'pendiente'=> 0,
            };

            $compraId = DB::table('compras')->insertGetId([
                'empresa_id'   => $empresaId,
                'id_proveedor' => $prov['id'],
                'id_usuario'   => $userId,
                'estado'       => $estado,
                'fecha'        => $fecha,
                'monto_total'  => $montoTotal,
                'cuit'         => $prov['cuit'],
                'metodo_pago'  => $metodoPago,
                'estado_deuda' => $estadoDeuda,
                'monto_pagado' => $montoPagado,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            foreach ($lineas as [$pIdx, $cant, $precioC, $precioV]) {
                $prod = $prodList[$pIdx] ?? null;
                if (!$prod) continue;
                DB::table('lineas_compras')->insert([
                    'id_compra'    => $compraId,
                    'id_producto'  => $prod['id'],
                    'precio_compra'=> $precioC,
                    'precio_venta' => $precioV,
                    'cantidad'     => $cant,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }

            // Pago parcial de proveedor
            if ($estadoDeuda === 'parcial') {
                DB::table('pagos_proveedor')->insert([
                    'id_compra'   => $compraId,
                    'id_usuario'  => $userId,
                    'monto'       => $montoPagado,
                    'fecha'       => $fecha,
                    'metodo_pago' => $metodoPago,
                    'nota'        => 'Pago parcial inicial',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
        $this->command->info('Compras creadas: ' . count($comprasData));

        // ═══════════════════════════════════════════════════════════════
        // VENTAS (90)
        // ═══════════════════════════════════════════════════════════════
        $metodos  = ['efectivo', 'transferencia', 'tarjeta'];
        $ventaNum = 1000;

        // Ventas en efectivo/tarjeta/transferencia — ya cobradas (70 ventas)
        $ventasNormales = [
            // [cliente_idx, fecha, hora, metodo, productos[[prod_idx, cant]]]
            [2,  '2026-05-05', '09:30', 'efectivo',      [[0,2],[4,3],[39,2]]],
            [4,  '2026-05-06', '10:15', 'tarjeta',       [[30,4],[31,2],[32,1]]],
            [7,  '2026-05-07', '11:00', 'efectivo',      [[59,3],[60,5],[61,2]]],
            [10, '2026-05-08', '14:30', 'transferencia', [[8,2],[9,1],[13,3]]],
            [1,  '2026-05-09', '09:00', 'efectivo',      [[15,2],[16,3],[22,1]]],
            [5,  '2026-05-10', '16:45', 'tarjeta',       [[39,2],[40,1],[43,2]]],
            [8,  '2026-05-11', '10:30', 'efectivo',      [[0,1],[1,1],[30,2]]],
            [12, '2026-05-12', '11:15', 'transferencia', [[80,2],[81,3],[84,1]]],
            [15, '2026-05-13', '09:45', 'efectivo',      [[59,4],[62,2],[65,5]]],
            [3,  '2026-05-14', '13:00', 'tarjeta',       [[33,1],[34,1],[35,1]]],
            [6,  '2026-05-15', '15:30', 'efectivo',      [[0,3],[4,2],[9,1]]],
            [9,  '2026-05-16', '10:00', 'transferencia', [[15,2],[16,2],[18,1]]],
            [11, '2026-05-17', '12:00', 'efectivo',      [[39,3],[41,2],[43,1]]],
            [14, '2026-05-18', '14:45', 'tarjeta',       [[59,2],[60,3],[61,1]]],
            [16, '2026-05-19', '09:15', 'efectivo',      [[80,1],[86,2],[88,3]]],
            [18, '2026-05-20', '11:30', 'transferencia', [[0,2],[5,3],[8,1]]],
            [20, '2026-05-21', '10:45', 'efectivo',      [[30,3],[32,2],[34,1]]],
            [22, '2026-05-22', '13:15', 'tarjeta',       [[59,3],[63,2],[65,4]]],
            [24, '2026-05-23', '09:00', 'efectivo',      [[15,1],[19,2],[21,3]]],
            [26, '2026-05-24', '14:00', 'transferencia', [[39,2],[44,1],[46,2]]],
            [0,  '2026-05-25', '10:30', 'efectivo',      [[0,4],[4,3],[80,1]]],
            [2,  '2026-05-26', '11:00', 'tarjeta',       [[1,2],[8,1],[30,2]]],
            [4,  '2026-05-27', '09:45', 'efectivo',      [[59,2],[61,1],[63,3]]],
            [6,  '2026-05-28', '13:30', 'transferencia', [[15,2],[22,3],[25,1]]],
            [8,  '2026-05-29', '10:15', 'efectivo',      [[39,4],[41,2],[48,1]]],
            [10, '2026-05-30', '15:00', 'tarjeta',       [[88,2],[89,3],[91,2]]],
            [12, '2026-05-31', '09:30', 'efectivo',      [[0,3],[5,4],[9,2]]],
            [14, '2026-06-01', '11:45', 'transferencia', [[30,2],[36,1],[37,1]]],
            [16, '2026-06-02', '10:00', 'efectivo',      [[59,3],[64,2],[66,4]]],
            [18, '2026-06-02', '14:30', 'tarjeta',       [[15,1],[17,2],[20,3]]],
            [20, '2026-06-03', '09:15', 'efectivo',      [[39,2],[40,3],[42,1]]],
            [22, '2026-06-03', '12:00', 'transferencia', [[80,2],[82,1],[85,2]]],
            [24, '2026-06-04', '10:45', 'efectivo',      [[0,2],[6,3],[11,1]]],
            [26, '2026-06-04', '15:15', 'tarjeta',       [[59,4],[60,2],[68,3]]],
            [28, '2026-06-05', '09:00', 'efectivo',      [[30,3],[34,1],[38,2]]],
            [30, '2026-06-05', '11:30', 'transferencia', [[15,2],[18,1],[23,3]]],
            [32, '2026-06-06', '10:15', 'efectivo',      [[39,3],[43,2],[47,1]]],
            [34, '2026-06-06', '13:45', 'tarjeta',       [[88,1],[90,2],[92,1]]],
            [36, '2026-06-07', '09:30', 'efectivo',      [[0,2],[3,1],[9,2]]],
            [38, '2026-06-07', '14:00', 'transferencia', [[59,3],[61,2],[65,4]]],
            [40, '2026-06-08', '10:45', 'efectivo',      [[30,2],[33,3],[35,1]]],
            [42, '2026-06-08', '12:15', 'tarjeta',       [[15,1],[16,2],[19,2]]],
            [44, '2026-06-09', '09:00', 'efectivo',      [[39,4],[41,1],[46,2]]],
            [46, '2026-06-09', '11:30', 'transferencia', [[80,3],[83,2],[87,1]]],
            [48, '2026-06-10', '10:00', 'efectivo',      [[0,2],[5,3],[8,2]]],
            [1,  '2026-06-10', '14:45', 'tarjeta',       [[59,2],[63,3],[67,1]]],
            [3,  '2026-06-11', '09:15', 'efectivo',      [[30,4],[31,2],[37,1]]],
            [5,  '2026-06-11', '12:00', 'transferencia', [[15,3],[20,2],[24,1]]],
            [7,  '2026-06-12', '10:30', 'efectivo',      [[39,2],[44,3],[49,2]]],
            [9,  '2026-06-12', '13:15', 'tarjeta',       [[88,2],[89,1],[91,3]]],
            [11, '2026-06-13', '09:00', 'efectivo',      [[0,3],[4,2],[7,1]]],
            [13, '2026-06-13', '11:45', 'transferencia', [[59,4],[62,2],[66,3]]],
            [15, '2026-06-13', '14:00', 'efectivo',      [[30,2],[32,3],[36,1]]],
            [17, '2026-06-14', '09:30', 'tarjeta',       [[15,2],[17,1],[21,3]]],
            [19, '2026-06-14', '10:45', 'efectivo',      [[39,3],[42,2],[45,1]]],
            [21, '2026-06-14', '13:00', 'transferencia', [[80,2],[84,3],[86,1]]],
            [23, '2026-06-15', '09:00', 'efectivo',      [[0,2],[5,4],[10,2]]],
            [25, '2026-06-15', '10:15', 'tarjeta',       [[59,3],[61,1],[64,3]]],
            [27, '2026-06-15', '11:30', 'efectivo',      [[30,3],[33,2],[38,1]]],
            [29, '2026-06-15', '12:45', 'transferencia', [[15,2],[18,3],[22,2]]],
            [31, '2026-06-15', '14:00', 'efectivo',      [[39,4],[43,1],[47,2]]],
            [33, '2026-06-15', '15:15', 'tarjeta',       [[88,3],[90,1],[93,2]]],
            [35, '2026-06-15', '16:30', 'efectivo',      [[0,1],[6,2],[9,3]]],
            [37, '2026-06-15', '17:00', 'transferencia', [[59,2],[65,4],[67,2]]],
            [39, '2026-06-15', '17:30', 'efectivo',      [[30,2],[34,3],[39,1]]],
            [41, '2026-06-15', '18:00', 'tarjeta',       [[15,3],[19,1],[23,2]]],
            [43, '2026-06-15', '18:30', 'efectivo',      [[39,2],[41,4],[48,2]]],
            [45, '2026-06-15', '19:00', 'transferencia', [[80,2],[82,3],[85,1]]],
            [47, '2026-06-15', '19:30', 'efectivo',      [[0,3],[4,1],[8,2]]],
            [49, '2026-06-15', '20:00', 'tarjeta',       [[59,2],[60,4],[63,3]]],
            [0,  '2026-06-15', '20:30', 'efectivo',      [[15,1],[16,3],[22,2]]],
        ];

        foreach ($ventasNormales as [$cliIdx, $fecha, $hora, $metodo, $lineas]) {
            $cliente = $clientesIds[$cliIdx];
            $montoTotal = 0;
            $lineasData = [];
            foreach ($lineas as [$pIdx, $cant]) {
                $prod = $prodList[$pIdx] ?? null;
                if (!$prod) continue;
                $montoTotal += $cant * $prod['precio'];
                $lineasData[] = [$prod['id'], $cant, $prod['precio']];
            }
            $ventaNum++;
            $ticket = 'VTA-' . $ventaNum;

            $ventaId = DB::table('ventas')->insertGetId([
                'empresa_id'    => $empresaId,
                'numero_ticket' => $ticket,
                'id_cliente'    => $cliente['id'],
                'id_usuario'    => $userId,
                'estado'        => 'confirmada',
                'fecha'         => $fecha,
                'hora'          => $hora,
                'metodo_pago'   => $metodo,
                'estado_pago'   => 'pagado',
                'monto_total'   => $montoTotal,
                'monto_cobrado' => $montoTotal,
                'cuit'          => $cliente['cuit'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            foreach ($lineasData as [$prodId, $cant, $precio]) {
                DB::table('lineas_ventas')->insert([
                    'id_venta'        => $ventaId,
                    'id_producto'     => $prodId,
                    'precio_venta'    => $precio,
                    'precio_original' => $precio,
                    'cantidad'        => $cant,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }
        }
        $this->command->info('Ventas normales creadas: ' . count($ventasNormales));

        // ── Ventas FIADAS (20) — pendientes/parciales ────────────────────
        $ventasFiadas = [
            // [cliente_idx, fecha, hora, estado_pago, pago_parcial_pct, productos[[prod_idx, cant]]]
            [0,  '2026-05-12', '10:00', 'pendiente', 0,    [[0,2],[4,3],[59,2]]],
            [1,  '2026-05-15', '11:30', 'parcial',   0.4,  [[30,2],[39,1],[65,3]]],
            [2,  '2026-05-20', '09:15', 'pendiente', 0,    [[8,1],[15,2],[88,2]]],
            [3,  '2026-05-22', '14:00', 'parcial',   0.6,  [[0,3],[1,1],[59,2]]],
            [4,  '2026-05-25', '10:45', 'pendiente', 0,    [[30,4],[39,2],[80,1]]],
            [5,  '2026-05-28', '15:00', 'parcial',   0.3,  [[15,2],[22,3],[59,1]]],
            [6,  '2026-06-01', '09:30', 'pendiente', 0,    [[0,2],[8,2],[39,3]]],
            [7,  '2026-06-03', '12:00', 'parcial',   0.5,  [[30,3],[59,4],[88,2]]],
            [8,  '2026-06-05', '10:30', 'pendiente', 0,    [[15,1],[39,2],[80,3]]],
            [9,  '2026-06-07', '11:15', 'parcial',   0.7,  [[0,3],[30,2],[59,1]]],
            [10, '2026-06-08', '09:00', 'pendiente', 0,    [[8,2],[15,3],[88,1]]],
            [11, '2026-06-09', '13:30', 'parcial',   0.4,  [[30,2],[39,4],[65,3]]],
            [12, '2026-06-10', '10:15', 'pendiente', 0,    [[0,1],[4,2],[59,3]]],
            [13, '2026-06-11', '14:00', 'parcial',   0.5,  [[15,2],[30,3],[80,1]]],
            [14, '2026-06-12', '09:45', 'pendiente', 0,    [[8,1],[39,2],[88,2]]],
            [15, '2026-06-13', '11:00', 'parcial',   0.6,  [[0,2],[59,3],[65,1]]],
            [16, '2026-06-13', '15:30', 'pendiente', 0,    [[30,3],[39,1],[80,2]]],
            [17, '2026-06-14', '10:00', 'parcial',   0.3,  [[15,2],[22,2],[59,2]]],
            [18, '2026-06-14', '13:45', 'pendiente', 0,    [[0,3],[8,2],[88,1]]],
            [19, '2026-06-15', '09:30', 'parcial',   0.5,  [[30,2],[39,3],[65,2]]],
        ];

        foreach ($ventasFiadas as [$cliIdx, $fecha, $hora, $estadoPago, $pagoPct, $lineas]) {
            $cliente = $clientesIds[$cliIdx];
            $montoTotal = 0;
            $lineasData = [];
            foreach ($lineas as [$pIdx, $cant]) {
                $prod = $prodList[$pIdx] ?? null;
                if (!$prod) continue;
                $montoTotal += $cant * $prod['precio'];
                $lineasData[] = [$prod['id'], $cant, $prod['precio']];
            }
            $montoCobrado = $estadoPago === 'parcial' ? round($montoTotal * $pagoPct) : 0;
            $ventaNum++;
            $ticket = 'VTA-' . $ventaNum;

            $ventaId = DB::table('ventas')->insertGetId([
                'empresa_id'    => $empresaId,
                'numero_ticket' => $ticket,
                'id_cliente'    => $cliente['id'],
                'id_usuario'    => $userId,
                'estado'        => 'confirmada',
                'fecha'         => $fecha,
                'hora'          => $hora,
                'metodo_pago'   => 'fiado',
                'estado_pago'   => $estadoPago,
                'monto_total'   => $montoTotal,
                'monto_cobrado' => $montoCobrado,
                'cuit'          => $cliente['cuit'],
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            foreach ($lineasData as [$prodId, $cant, $precio]) {
                DB::table('lineas_ventas')->insert([
                    'id_venta'        => $ventaId,
                    'id_producto'     => $prodId,
                    'precio_venta'    => $precio,
                    'precio_original' => $precio,
                    'cantidad'        => $cant,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            // Pago parcial
            if ($estadoPago === 'parcial' && $montoCobrado > 0) {
                DB::table('pagos_cliente')->insert([
                    'id_venta'    => $ventaId,
                    'id_usuario'  => $userId,
                    'monto'       => $montoCobrado,
                    'fecha'       => $fecha,
                    'metodo_pago' => 'efectivo',
                    'nota'        => 'Abono inicial',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
        $this->command->info('Ventas fiadas creadas: ' . count($ventasFiadas));
        $this->command->info('¡Datos cargados correctamente! Total ventas: ' . ($ventaNum - 1000));
    }
}
