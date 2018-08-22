<?php


use Contica\eFacturacion\Facturador;

require __DIR__ . '/../vendor/autoload.php';
require 'configs.php';

$invoicer = new Facturador(['contra' => $databasePassword]);
$xml = $invoicer->cogerXmlComprobante('50621081800060396091600100001010000000025161688878');
$file = fopen(__DIR__ . "/getxml.xml", "w");
fwrite($file, $xml);
fclose($file);