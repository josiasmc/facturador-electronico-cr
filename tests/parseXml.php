<?php
/**+
 * Parser for Ministerio de Hacienda docs
 */

require __DIR__ . '/../vendor/autoload.php';
use Sabre\Xml\Service;
$filename = '/../src/respuesta.xml';
//$filename = '/recibido.xml';
$file = fopen(__DIR__ . $filename, 'r');
$xml = fread($file, filesize(__DIR__ . $filename));
fclose($file);
//Eliminar la firma
$xml = preg_replace("/.ds:Signature[\s\S]*ds:Signature./m", '', $xml);
//Coger el elemento root del comprobante
$s = stripos($xml, '<', 10) + 1;
$e = stripos($xml, ' ', $s);
$root = substr($xml, $s, $e - $s);
//Coger el namespace del comprobante
$s = stripos($xml, 'xmlns=') + 7;
$e = stripos($xml, '"', $s+10);
$ns = substr($xml, $s, $e - $s);
$xmlns = '{' .$ns .'}';

$service = new Service;

$f_keyValue = function (Sabre\Xml\Reader $reader) {
    global $ns;
    return Sabre\Xml\Deserializer\keyValue($reader, $ns);
};
$f_repeatingElements = function (Sabre\Xml\Reader $reader) {
    global $ns;
    return Sabre\Xml\Deserializer\repeatingElements($reader, $ns);
};

$service->elementMap = [
    $xmlns.$root => $f_keyValue,
    $xmlns.'Emisor' => $f_keyValue,
    $xmlns.'Receptor' => $f_keyValue,
    $xmlns.'Identificacion'  => $f_keyValue,
    $xmlns.'Ubicacion' => $f_keyValue,
    $xmlns.'Telefono' => $f_keyValue,
    $xmlns.'Telefono' => $f_keyValue,
    $xmlns.'ResumenFactura' => $f_keyValue,
    $xmlns.'LineaDetalle' => $f_keyValue,
    $xmlns.'Codigo' => $f_keyValue,
    $xmlns.'Normativa' => $f_keyValue,
    $xmlns.'Otros' => $f_keyValue
];
if (substr_count($xml, '<MedioPago>') > 1) {
    $service->elementMap[$xmlns.'MedioPago'] = $f_repeatingElements;
}

if (substr_count($xml, '<LineaDetalle>') > 1) {
    $service->elementMap[$xmlns.'DetalleServicio'] = $f_repeatingElements;
} else {
    $service->elementMap[$xmlns.'DetalleServicio'] = $f_keyValue;
}
$parsed = $service->parse($xml);
var_dump($parsed);
foreach ($parsed as $key => $value) {
    echo "\n$key";
}
echo "\nDetalleMensaje: ".$parsed['DetalleMensaje'];