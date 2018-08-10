<?php


use Contica\eFacturacion\Facturador;

require __DIR__ . '/../vendor/autoload.php';

$invoicer = new Facturador(['contra' => 'localhost']);

date_default_timezone_set('America/Costa_Rica');
$datos = [
    'NumeroConsecutivo' => '00100001010000000010',
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
    'MedioPago' => '01',
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
            'PrecioUnitario' => '1.00',
            'MontoTotal' => '1.00',
            'SubTotal' => '1.00',
            'MontoTotalLinea' => '1.00'
            ],
            [
            'NumeroLinea' => '2',
            'Codigo' => [
                'Tipo' => '04',
                'Codigo' => '010010'
            ],
            'Cantidad' => '2',
            'UnidadMedida' => 'h',
            'Detalle'  => 'Servicio al cliente',
            'PrecioUnitario' => '1.00',
            'MontoTotal' => '2.00',
            'SubTotal' => '2.00',
            'MontoTotalLinea' => '2.00'
            ]
        ]
        ],
    'ResumenFactura' => [
        'TotalServExentos' => '3.00',
        'TotalExento' => '3.00',
        'TotalVenta' => '3.00',
        'TotalVentaNeta' => '3.00',
        'TotalComprobante' => '3.00'
    ],
    'Normativa' => [
        'NumeroResolucion' => 'DGT-R-48-2016',
        'FechaResolucion' => '07-10-2016 08:00:00'
    ]
];

try {
    
    $cl = $invoicer->enviarComprobante($datos);
    echo "Clave generada: $cl";

} catch (\GuzzleHttp\Exception\ClientException $err)  {
    $file = fopen(__DIR__ . "/error.html", "w");
    fwrite($file, $err);
    fclose($file);
}
