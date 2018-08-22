<?php
use Contica\eFacturacion\Facturador;
require __DIR__ . '/../vendor/autoload.php';
require 'configs.php';

    $facturador = new Facturador(['contra' => $databasePassword]);
    $cert = fopen(__DIR__ . '/cert.p12', 'r');
    $company = [
        'nombre' => $nameCompany,
        'email' => $emailCompany,
        'certificado' => fread($cert, filesize(__DIR__ . '/cert.p12')),
        'usuario' => $userHacienda,
        'contra' => $passwordHacienda,
        'pin' => $pinCertificate,
        'id_ambiente' => $id_ambiente
    ];
    fclose($cert);
    $facturador->guardarEmpresa(603960916, $company);
