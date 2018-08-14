<?php
/**+
 * Parser for Ministerio de Hacienda docs
 */

require __DIR__ . '/../vendor/autoload.php';
use Sabre\Xml\Service;
$filename = '/../src/respuesta.xml';
$file = fopen(__DIR__ . $filename, 'r');
$xml = fread($file, filesize(__DIR__ . $filename));
fclose($file);
$xml = preg_replace('<ds:Signature.*</ds:Signature>', '', $xml);
$s = stripos($xml, '<', 10) + 1;
$e = stripos($xml, ' ', $s);
$root = substr($xml, $s, $e - $s);
echo $root;
$s = stripos($xml, 'xmlns=') + 7;
$e = stripos($xml, '"', $s+10);
$ns = substr($xml, $s, $e - $s);
$xmlns = '{' .$ns .'}';
$service = new Service;

$service->elementMap = [
    $xmlns.$root 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Emisor' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Receptor' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Identificacion' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Ubicacion' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Telefono' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Telefono' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'ResumenFactura' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'DetalleServicio' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\repeatingElements($reader, $ns);
        },
    $xmlns.'LineaDetalle' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Normativa' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        },
    $xmlns.'Otros' 
        => function (Sabre\Xml\Reader $reader) {
            global $ns;
            return Sabre\Xml\Deserializer\keyValue($reader, $ns);
        }
];

print_r($service->parse($xml));