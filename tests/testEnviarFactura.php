<?php


use Contica\eFacturacion\Facturador;

require __DIR__ . '/../vendor/autoload.php';

$invoicer = new Facturador(['contra' => 'localhost']);

$datos = [
    'NumeroConsecutivo' => '00100001010000000001',
    'FechaEmision' => date('c'),
    'Emisor' => [
        'Nombre' => 'Soluciones Induso',
        'Identificacion' => [
            'Tipo' => '01',
            'Numero' => '603960916'
        ],
        'Ubicacion' => [
            'Provincia' => '6',
            'Canton' => '08',
            'Distrito' => '01',
            'OtrasSenas' => '600 metros oeste entrada La Manchuria'
        ],
        'CorreoElectronico' => 'josiasmc@emypeople.net'
    ],
    'Receptor' => [
        'Nombre' => 'Lacteos Eucalipto',
        'Identificacion' => [
            'Tipo' => '01',
            'Numero' => '603700475'
        ],
        'Ubicacion' => [
            'Provincia' => '6',
            'Canton' => '08',
            'Distrito' => '01',
            'OtrasSenas' => '1 km oeste Escuela San Rafael'
        ],
        'CorreoElectronico' => 'smcc@emypeople.net'
    ],
    'CondicionVenta' => '01',
    'MedioPago' => ['01', '02'],
    'DetalleServicio' => [
        'LineaDetalle' => [
            [
            'NumeroLinea' => '1',
            'Codigo' => [
                'Tipo' => '04',
                'Codigo' => '010001'
            ],
            'Cantidad' => '1',
            'UnidadMedida' => 'Unid',
            'Detalle'  => 'Servicio de programacion',
            'PrecioUnitario' => '15000.00',
            'MontoTotal' => '15000.00',
            'Subtotal' => '15000.00',
            'MontoTotalLinea' => '15000.00'
            ],
            [
            'NumeroLinea' => '2',
            'Codigo' => [
                'Tipo' => '04',
                'Codigo' => '010010'
            ],
            'Cantidad' => '2',
            'UnidadMedida' => 'Hr',
            'Detalle'  => 'Servicio al cliente',
            'PrecioUnitario' => '3000.00',
            'MontoTotal' => '6000.00',
            'Subtotal' => '6000.00',
            'MontoTotalLinea' => '6000.00'
            ]
        ]
        ],
    'ResumenFactura' => [
        'TotalServiciosExentos' => '21000.00',
        'TotalExento' => '21000.00',
        'TotalVenta' => '21000.00',
        'TotalVentaNeta' => '21000.00',
        'TotalComprobante' => '21000.00'
    ],
    'Normativa' => [
        'NumeroResolucion' => 'DGT-R-48-2016',
        'FechaResolucion' => '07-10-2016 08:00:00'
    ]
];

try {
    
    $xml = $invoicer->enviarComprobante($datos);

    $file = fopen(__DIR__ . "/factura.xml", "w");
    fwrite($file, $xml);
    fclose($file);
} catch (\GuzzleHttp\Exception\ClientException $err)  {
    $file = fopen(__DIR__ . "/error.html", "w");
    fwrite($file, $err);
    fclose($file);
}
