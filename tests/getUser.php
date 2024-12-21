<?php

use Contica\eFacturacion\Facturador;

require __DIR__ . '/../vendor/autoload.php';
require 'configs.php';
$invoicer = new Facturador(['password' => $databasePassword]);
$company = $invoicer->cogerEmpresa(901230456);
var_dump($company);
$cert = $invoicer->cogerCertificadoEmpresa(901230456);

$file = fopen(__DIR__ . "/fromDB.p12", "w");
fwrite($file, $cert);
fclose($file);
