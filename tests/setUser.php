<?php
use Contica\eFacturacion\Facturador;
require __DIR__ . '/../vendor/autoload.php';

    $facturador = new Facturador(['contra' => 'localhost']);
    $cert = fopen(__DIR__ . '/cert.p12', 'r');
    $company = [
        'nombre' => 'Soluciones Induso',
        'email' => 'josiasmc@emypeople.net',
        'certificado' => fread($cert, filesize(__DIR__ . '/cert.p12')),
        'usuario' => 'cpf-06-0396-0916@stag.comprobanteselectronicos.go.cr',
        'contra' => 'Kt#@{};h@7s0(@;c2p*B',
        'pin' => '3141',
        'id_ambiente' => 1
    ];
    fclose($cert);
    $facturador->guardarEmpresa(603960916, $company);
