<?php
use Contica\eInvoicing\Invoicer;
require __DIR__ . '/../vendor/autoload.php';

    $invoicer = new Invoicer(['password' => 'localhost']);
    $cert = fopen(__DIR__ . '/cert.p12', 'r');
    $company = [
        'nombre' => 'PHP Unit Testing',
        'email' => 'josiasmc@emypeople.net',
        'certificado' => fread($cert, filesize(__DIR__ . '/cert.p12')),
        'usuario' => 'cpf-06-0396-0916@stag.comprobanteselectronicos.go.cr',
        'contra' => '0Q(PWc{h*2@u=^]G.C?+',
        'pin' => '3141',
        'id_ambiente' => 1
    ];
    fclose($cert);
    $invoicer->setCompany(901230456, $company);
