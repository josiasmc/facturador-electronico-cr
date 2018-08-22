<?php
use Contica\eFacturacion\Facturador;
use Contica\eFacturacion\Token;
use \GuzzleHttp\Client;

require __DIR__ . '/../vendor/autoload.php';
$container = include __DIR__ . '/container.php';

$clave = '50621081800060396091600100001010000000025161688878';
$facturador = new Facturador(['contra' => 'josi14.cr']);
$ans = $facturador->interrogarRespuesta($clave);
echo 'Clave: ' . $clave . "\n";
echo 'Estado: '.$ans['Estado'];
echo "\nMensaje: ".$ans['Mensaje'];
echo "\n";
