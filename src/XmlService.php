<?php
/**
 * Clase para modificar la clase que crea xmls
 *  
 * PHP version 7.3
 * 
 * @category  Facturacion-electronica
 * @package   Contica\Facturacion
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

/**
 * Todas los metodos para crear los archivos XML
 * 
 * @category Facturacion-electronica
 * @package  Contica\Facturacion\XmlService
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class XmlService extends \Sabre\Xml\Service
{
    /**
     * Generates an XML document in one go.
     *
     * The $rootElement must be specified in clark notation.
     * The value must be a string, an array or an object implementing
     * XmlSerializable. Basically, anything that's supported by the Writer
     * object.
     *
     * $contextUri can be used to specify a sort of 'root' of the PHP application,
     * in case the xml document is used as a http response.
     *
     * This allows an implementor to easily create URI's relative to the root
     * of the domain.
     *
     * @param string                       $rootElementName Root name
     * @param string|array|XmlSerializable $value           Values to write
     * @param string                       $contextUri      Uri
     * 
     * @return string
     */
    function write(string $rootElementName, $value, string $contextUri = null) 
    {

        $w = new XmlWriter;
        $w->namespaceMap = $this->namespaceMap;
        $w->openMemory();
        $w->contextUri = $contextUri;
        $w->setIndent(true);
        $w->startDocument("1.0", "UTF-8");
        $w->writeElement($rootElementName, $value);
        return $w->outputMemory();

    }

    /**
     * Generate the XML signature in one go
     * 
     * @param string|array $rootElementName Root element
     * @param string|array $value           Values to write
     * 
     * @return string
     */
    function writeSignature($rootElementName, $value)
    {
        $w = new XmlWriter;
        $w->namespaceMap = $this->namespaceMap;
        $w->openMemory();
        $w->setIndent(true);
        $w->sig = true;
        if (is_array($rootElementName)) {
            $w->startElement($rootElementName['name']);
            $w->writeAttributes($rootElementName['attributes']);
            $w->write($value);
            $w->endElement();
        } else {
            $w->writeElement($rootElementName, $value);
        }
        return $w->outputMemory();
    }

    /**
     * Generate XML fragrments for the signature
     * 
     * @param string|array $rootElementName Root element
     * @param string|array $value           Values to write
     * 
     * @return string
     */
    function writeFragment($rootElementName, $value)
    {
        $w = new XmlWriter;
        $w->namespaceMap = $this->namespaceMap;
        $w->openMemory();
        $w->setIndent(true);
        $w->sig = true;
        $w->disableNs();
        if (is_array($rootElementName)) {
            $w->startElement($rootElementName['name']);
            $w->writeAttributes($rootElementName['attributes']);
            $w->write($value);
            $w->endElement();
        } else {
            $w->writeElement($rootElementName, $value);
        }
        return $w->outputMemory();
    }


    /**
     * Parses a document in full.
     *
     * Input may be specified as a string or readable stream resource.
     * The returned value is the value of the root document.
     *
     * Specifying the $contextUri allows the parser to figure out what the URI
     * of the document was. This allows relative URIs within the document to be
     * expanded easily.
     *
     * The $rootElementName is specified by reference and will be populated
     * with the root element name of the document.
     *
     * @param string|resource $input
     * @throws ParseException
     * @return array|object|string
     */
    function parse($input, string $contextUri = null, string &$rootElementName = null) {

        if (is_resource($input)) {
            // Unfortunately the XMLReader doesn't support streams. When it
            // does, we can optimize this.
            $input = stream_get_contents($input);
        }
        $r = new XmlReader;
        $r->contextUri = $contextUri;
        $r->xml($input);

        $result = $r->parse();
        $rootElementName = $result['name'];
        return $result['value'];

    }
}