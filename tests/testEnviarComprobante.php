<?php

use Contica\eFacturacion\Facturador;
use Contica\eFacturacion\Comprobante;

require __DIR__ . '/../vendor/autoload.php';

$container = include 'container.php';
$datos = [
    'NumeroConsecutivo' => '00100001010000000001',
    'FechaEmision' => '30 julio 2018',
    'Emisor' => [
        'Nombre' => 'Soluciones Induso',
        'Identificacion' => [
            'Tipo' => '01',
            'Numero' => '603960916'
        ],
        'Ubicacion' => '600 metros oeste entrada a La Manchuria',
        'CorreoElectronico' => 'josiasmc@emypeople.net'
    ],
];
$comprobante = new Comprobante($container, $datos);
$clave = $comprobante->cogerClave();
echo 'La clave numerica es: ' . $clave . '
';
echo strlen($clave) . '
';
