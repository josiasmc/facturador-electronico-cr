<?php

/**+
 * Parser for Ministerio de Hacienda docs
 */

use Contica\Facturacion\Comprobante;

require __DIR__ . '/../vendor/autoload.php';
require 'configs.php';

$filename = '/recibido.xml';
$xml = file_get_contents($filename);
//Eliminar la firma
$result = Comprobante::analizarXML($xml);
print_r($result);
