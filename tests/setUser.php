<?php
use Contica\eFacturacion\Facturador;
require __DIR__ . '/../vendor/autoload.php';

    $invoicer = new Facturador(['contraseña' => 'localhost']);
    $cert = fopen(__DIR__ . '/cert.p12', 'r');
    $company = [
        'nombre' => 'PHPtesting',
        'email' => 'josiasmc@emypeople.net',
        'certificado' => fread($cert, filesize(__DIR__ . '/cert.p12')),
        'usuario' => 'cpf-06-0396-0916@stag.comprobanteselectronicos.go.cr',
        'contra' => '0Q(PWc{h*2@u=^]G.C?+',
        'pin' => '3141',
        'id_ambiente' => 1
    ];
    fclose($cert);
    $invoicer->guardarEmpresa(901230456, $company);
