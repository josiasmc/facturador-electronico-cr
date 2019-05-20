<?php
/**
 * Clase para serializar los comprobantes
 *  
 * PHP version 7.1
 * 
 * @category  Facturacion-electronica
 * @package   Contica\eFacturacion
 * @author    Josias Martin <josiasmc@emypeople.net>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturacion-electronica-cr
 */

namespace Contica\eFacturacion;

use \Sabre\Xml\Service;

/**
 * Todas los metodos para crear los archivos XML
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion\CreadorXML
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class XmlWriter  extends \Sabre\Xml\Writer
{
    protected $written = array();
    public $sig = false;

    /**
     * Writes a value to the output stream.
     *
     * @param mixed $value Serializable
     * 
     * @return void
     */
    function write($value) 
    {
        if ($this->sig) {
            $this->sigSerialize($value);
        } else {
            $this->xmlSerialize($value);
        }
    }

    
    /**
     * Esta funcion serializa el array a los nodos del xml
     * 
     * @param mixed $value El valor para serializar
     * 
     * @return string El texto xml
     */
    public function xmlSerialize($value)
    {
        if (is_scalar($value)) {

            // String, integer, float, boolean
            $this->text((string)$value);
    
        } elseif (is_null($value)) {

            // nothing!
    
        } elseif (is_array($value) && array_key_exists('name', $value)) {

            // if the array had a 'name' element, we assume that this array
            // describes a 'name' and optionally 'attributes' and 'value'.
    
            $name = $value['name'];
            $attributes = isset($value['attributes']) ? $value['attributes'] : [];
            $value = isset($value['value']) ? $value['value'] : null;
    
            $this->startElement($name);
            $this->writeAttributes($attributes);
            $this->write($value);
            $this->endElement();
    
        } elseif (is_array($value)) {
            
            foreach ($value as $name => $item) {
                if (is_string($name) && is_array($item) && array_key_exists(0, $item)) {

                    // This item has a numeric index. We just loop through the
                    // array and throw it back in the writer.
                    foreach ($item as $value) {
                        $this->write([$name => $value]);
                    } 
                }/* elseif (is_string($name) && is_array($item) && isset($item['name'])) {

                    // The key is used for a name, but $item has 'name' and
                    // possibly 'value', so create a child node for it
                    $this->startElement($name);
                    $this->write($item);
                    $this->endElement();
    
                }*/ elseif (is_array($item) && isset($item['name'])) {

                    // if the array had a 'name' element, we assume that this array
                    // describes a 'name' and optionally 'attributes' and 'value'.
            
                    $name = $item['name'];
                    $attributes = isset($item['attributes']) ? $item['attributes'] : [];
                    $item = isset($item['value']) ? $item['value'] : null;
            
                    $this->startElement($name);
                    $this->writeAttributes($attributes);
                    $this->write($item);
                    $this->endElement();
            
                } elseif (is_string($name)) {
                    // This was a plain key-value array.
                    $this->startElement($name);
                    $this->write($item);
                    $this->endElement();
                } else {

                    throw new \InvalidArgumentException(
                        'The writer does not know how to serialize arrays with keys of type: '.
                        gettype($name). "\n".
                        var_dump($value)
                    );
                }
            }
        } else {
            throw new \InvalidArgumentException('The writer cannot serialize values of type: ' . gettype($value));
        }
    }

    /**
     * Esta funcion serializa el array a los nodos del xml
     * 
     * @param mixed $value El valor para serializar
     * 
     * @return string El texto xml
     */
    public function sigSerialize($value)
    {
        if (is_scalar($value)) {

            // String, integer, float, boolean
            $this->text((string)$value);
    
        } elseif (is_null($value)) {

            // nothing!
    
        } elseif (is_array($value) && array_key_exists('name', $value)) {

            // if the array had a 'name' element, we assume that this array
            // describes a 'name' and optionally 'attributes' and 'value'.
    
            $name = $value['name'];
            $attributes = isset($value['attributes']) ? $value['attributes'] : [];
            $value = isset($value['value']) ? $value['value'] : null;
    
            $this->startElement($name);
            $this->writeAttributes($attributes);
            $this->write($value);
            $this->endElement();
    
        } elseif (is_array($value)) {
            
            foreach ($value as $name => $item) {
                if (is_string($name) && is_array($item) && isset($item['name'])) {

                    // The key is used for a name, but $item has 'name' and
                    // possibly 'value', so create a child node for it
                    $this->startElement($name);
                    $this->write($item);
                    $this->endElement();
    
                } elseif (is_array($item) && isset($item['name'])) {

                    // if the array had a 'name' element, we assume that this array
                    // describes a 'name' and optionally 'attributes' and 'value'.
            
                    $name = $item['name'];
                    $attributes = isset($item['attributes']) ? $item['attributes'] : [];
                    $item = isset($item['value']) ? $item['value'] : null;
            
                    $this->startElement($name);
                    $this->writeAttributes($attributes);
                    $this->write($item);
                    $this->endElement();
            
                } elseif (is_string($name)) {
                    // This was a plain key-value array.
                    $this->startElement($name);
                    $this->write($item);
                    $this->endElement();
                } else {

                    throw new \InvalidArgumentException(
                        'The writer does not know how to serialize arrays with keys of type: '.
                        gettype($name). "\n".
                        var_dump($value)
                    );
                }
            }
        } else {
            throw new \InvalidArgumentException('The writer cannot serialize values of type: ' . gettype($value));
        }
    }

    /**
     * Disable namespace declaration
     * 
     * @return null
     */
    public function disableNs()
    {
        $this->namespacesWritten = true;
    }

    /**
     * Opens a new element.
     *
     * You can either just use a local elementname, or you can use clark-
     * notation to start a new element.
     *
     * Example:
     *
     *     $writer->startElement('{http://www.w3.org/2005/Atom}entry');
     *
     * Would result in something like:
     *
     *     <entry xmlns="http://w3.org/2005/Atom">
     *
     * Note: this function doesn't have the string typehint, because PHP's
     * XMLWriter::startElement doesn't either.
     *
     * @param string $name Name of the comment
     * 
     * @return string
     */
    function startElement($name) : bool 
    {

        if ($name[0] === '{') {
            // A namespace is given
            list($namespace, $localName) 
                = Service::parseClarkNotation($name);

            if (array_key_exists($namespace, $this->namespaceMap)) {
                // The namespace exists in the namespace map given
                $result = $this->startElementNS(
                    $this->namespaceMap[$namespace] === '' ? null : $this->namespaceMap[$namespace],
                    $localName,
                    null
                );
                // Check to see if needed to write namespace
                if ($this->sig && !$this->namespacesWritten) {
                    if (!array_key_exists($namespace, $this->written)) {
                        // Save the namespace
                        $prefix = $this->namespaceMap[$namespace];
                        $this->written[$namespace] = $prefix;
                        // Write the namespace attribute
                        $this->writeAttribute(($prefix ? 'xmlns:' . $prefix : 'xmlns'), $namespace);
                    }
                }
            } else {

                // An empty namespace means it's the global namespace. This is
                // allowed, but it mustn't get a prefix.
                if ($namespace === "" || $namespace === null) {
                    $result = $this->startElement($localName);
                    $this->writeAttribute('xmlns', '');
                } else {
                    if (!isset($this->adhocNamespaces[$namespace])) {
                        $this->adhocNamespaces[$namespace] = 'x' . (count($this->adhocNamespaces) + 1);
                    }
                    $result = $this->startElementNS($this->adhocNamespaces[$namespace], $localName, $namespace);
                }
            }

        } else {
            $result = parent::startElement($name);
        }

        if (!$this->sig) {
            if (!$this->namespacesWritten) {

                foreach ($this->namespaceMap as $namespace => $prefix) {
                    $this->writeAttribute(($prefix ? 'xmlns:' . $prefix : 'xmlns'), $namespace);
                }
                $this->namespacesWritten = true;

            }
        }

        return $result;

    }
}