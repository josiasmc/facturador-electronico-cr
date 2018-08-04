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
    /**
     * Writes a value to the output stream.
     *
     * @param mixed $value Serializable
     * 
     * @return void
     */
    function write($value) 
    {

        $this->xmlSerialize($value);

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
                if (is_array($item) && array_key_exists(0, $item)) {

                    // This item has a numeric index. We just loop through the
                    // array and throw it back in the writer.
                    foreach ($item as $value) {
                        $this->write([$name => $value]);
                    } 
                } elseif (is_array($item) && array_key_exists('name', $item)) {

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
}