<?php
/**
 * Clase para leer los comprobantes
 *  
 * PHP version 7.3
 * 
 * @category  Facturacion-electronica
 * @package   Contica\eFacturacion
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;
use \Sabre\Xml\Reader;

/**
 * Funciones especiales para leer xmls de factura electronica
 * 
 * @category Facturacion-electronica
 * @package  Contica\Facturacion\XmlReader
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class XmlReader
{
    /**
     * Lector para nodos que aparecen en fila
     * 
     * @param Reader $reader    The deserializer reader
     * @param string $namespace Root namespace
     * 
     * @return object
     */
    public static function repeatKeyValue(Reader $reader, string $namespace)
    {
        // If there's no children, we don't do anything.
        if ($reader->isEmptyElement) {
            $reader->next();
            return [];
        }

        $values = [];

        $reader->read();
        do {

            if ($reader->nodeType === Reader::ELEMENT) {
                if ($namespace !== null && $reader->namespaceURI === $namespace) {
                    $localName = $reader->localName;
                    $value = $reader->parseCurrentElement()['value'];
                    if (isset($values[$localName])) {
                        if (is_array($values[$localName]) && array_key_exists(0, $values[$localName])) {
                            $values[$localName][] = $value;
                        } else {
                            $values[$localName] = array($values[$localName], $value);
                        }
                    } else {
                        $values[$localName] = $value;
                    }
                } else {
                    $clark = $reader->getClark();
                    $values[$clark] = $reader->parseCurrentElement()['value'];
                }
            } else {
                $reader->read();
            }
        } while ($reader->nodeType !== Reader::END_ELEMENT);

        $reader->read();

        return $values;
    }

    /**
     * Lector para el campo de Codigo
     * 
     * @param Reader $reader    The deserializer reader
     * @param string $namespace Root namespace
     * 
     * @return mixed
     */
    public static function codigoParser(Reader $reader, string $namespace)
    {
        // If there's no children, we don't do anything.
        if ($reader->isEmptyElement) {
            $reader->next();
            return '';
        }
        
        $reader->read();
        $values = [];
        $text = null;

        do {
            $type = $reader->nodeType;
            if ($type === Reader::TEXT || $type === Reader::CDATA) {
                $text .= $reader->value;
                $reader->read();
            } else if ($type === Reader::ELEMENT) {
                $values[$reader->localName] = $reader->parseCurrentElement()['value'];
            } else {
                $reader->read();
            }
        } while ($reader->nodeType !== Reader::END_ELEMENT);

        $reader->read();

        return $text ? $text : $values;
    }
}