<?php
/**+
 * Parser for Ministerio de Hacienda docs
 */

use Contica\eFacturacion\Facturador;
require __DIR__ . '/../vendor/autoload.php';
require 'configs.php';

$facturador = new Facturador(['contra' => $databasePassword]);

$filename = '/home/josias/problema.xml';
//$filename = '/recibido.xml';
$file = fopen($filename, 'r');
$xml = fread($file, filesize($filename));
fclose($file);
//Eliminar la firma
$result = $facturador->analizarComprobante($xml);
print_r($result);