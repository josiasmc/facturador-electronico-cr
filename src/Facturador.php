<?php
/**
 * Facturador electronico para Costa Rica
 * 
 * Este componente suple una interfaz para la integración de facturación
 * electrónica con el Ministerio de Hacienda en Costa Rica
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

use \Defuse\Crypto\Key;
use \Sabre\Xml\Service;
use \GuzzleHttp\Client;

/**
 * El proveedor de facturacion
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class Facturador
{
    protected $container;
    
    /**
     * Invoicer constructor
     * 
     * @param array $settings Ajustes para el facturador
     */
    public function __construct($settings = array())
    {
        $config = array_merge(
            [
            'servidor' => 'localhost',
            'base_datos' => 'e_facturacion',
            'usuario' => 'root',
            'contra' => 'password',
            'llave' => 
                'def0000057b1b0528f59f7ba3da8a25f60e9498bb0060'.
                'a652843681d9f8ca53746679318aab2e54a9d4c2485f4'.
                '6441709de9f0c4aa494dc31acf3d64484f88089296ebe6',
            'callbackUrl' => ''
            ], $settings
        );
        // Crear conexion a la base de datos
        $db = Storage::mySql(
            $config['servidor'],
            $config['usuario'],
            $config['contra'],
            $config['base_datos']
        );
        // Initialize the container
        $this->container = [
            'cryptoKey' => Key::loadFromAsciiSafeString($config['llave']),
            'db' => $db,
            'callbackUrl' => $config['callbackUrl']
        ];
    }

    /**
     * Crear o modificar una empresa
     *
     * @param int   $id    Cedula de la empresa
     * @param array $datos Los datos de la empresa
     *
     * @return bool El resultado de la operacion
     */
    public function guardarEmpresa($id, $datos)
    {
        $empresas = new Empresas($this->container);
        if (!$empresas->exists($id)) {
            return $empresas->add($id, $datos);
        } else {
            return $empresas->modify($id, $datos);
        }
    }

    /**
     * Coger los datos de una empresa
     * 
     * @param int $id Cedula de la empresa
     * 
     * @return array Todos los campos de texto de la empresa
     */
    public function cogerEmpresa($id)
    {
        $empresas = new Empresas($this->container);
        return $empresas->get($id);
    }

     /**
     * Coger el certificado de la empresa
     * 
     * @param int $id Cedula de la empresa
     * 
     * @return file El certificado de la empresa
     */
    public function cogerCertificadoEmpresa($id)
    {
        $empresas = new Empresas($this->container);
        return $empresas->getCert($id);
    }

    /**
     * Enviar compropbante al Ministerio de Hacienda
     * 
     * @param array $datos Los datos para construir el comprobante para enviar
     * 
     * @return string La clave numerica del comprobante
     */
    public function enviarComprobante($datos)
    {
        $comprobante = new Comprobante($this->container, $datos);
        return $comprobante->enviar();       
    }

    /**
     * Procesar mensaje Hacienda
     * 
     * @param string $cuerpo El cuerpo del mensaje de Hacienda
     * 
     * @return array Estado del comprobante enviado
     */
    public function procesarMensajeHacienda($cuerpo)
    {
        $db = $this->container['db'];
        $cuerpo = json_decode($cuerpo, true);
        $ind_estado = str_lower($cuerpo['ind-estado']);
        if (($ind_estado == 'recibido') || ($ind_estado == 'procesando')) {
            $ind_estado = 'enviado';
        }
        $clave = $cuerpo['clave'];
        if ($ind_estado == 'aceptado' || $ind_estado == 'rechazado') {
            // Procesar el xml enviado
            $xml = base64_decode($cuerpo['respuesta-xml']);
            /*$file = fopen(__DIR__ . "/respuesta.xml", "w");
            fwrite($file, $xml);
            fclose($file);*/
            $estado = ($ind_estado == 'aceptado') ? 3 : 4; //aceptado : rechazado
            $xmldb = $db->real_escape_string(gzcompress($xml, 9));
            $sql = "UPDATE Emisiones 
                    SET Estado=$estado, Respuesta='$xmldb' 
                    WHERE Clave='$clave'";
            $db->query($sql);
            $parsedXml = $this->analizarComprobante($xml);
            return [
                'Clave' => $parsedXml['Clave'],
                'Estado' => $ind_estado,
                'Mensaje' => $parsedXml['DetalleMensaje'],
                'Xml' => $xml
            ];
        }
        return [
            'Estado' => $ind_estado,
            'Mensaje' => ''
        ];
    }

    /**
     * Revisar estado de comprobante
     * 
     * @param string $clave La clave del comprobante
     * 
     * @return int El estado (1:pendiente, 2:enviado, 3: aceptado, 4:rechazado)
     */
    function estadoComprobante($clave) 
    {   
        $db = $this->container['db'];
        $sql = "SELECT Estado
                FROM Emisiones
                WHERE Clave='$clave'";
        $res = $db->query($sql)->fetch_assoc();
        return $res['Estado'];
    }

    /**
     * Interrogar estado de comprobante en Hacienda
     * 
     * @param string $clave La clave del comprobante a interrogar
     * 
     * @return array El resultado
     */
    public function interrogarRespuesta($clave)
    {
        $db = $this->container['db'];
        $estado = $this->estadoComprobante($clave);
        if ($estado > 2) {
            //ya tenemos la respuesta de Hacienda en la base de datos
            $estado = ($estado == 3) ? 'aceptado' : 'rechazado'; //aceptado : rechazado
            $sql = "SELECT Respuesta
            FROM Emisiones
            WHERE Clave='$clave'";
            $xml = gzuncompress($db->query($sql)->fetch_assoc()['Respuesta']);
            $data = $this->analizarComprobante($xml);
            return [
                'Clave' => $data['Clave'],
                'Estado' => $estado,
                'Mensaje' => $data['DetalleMensaje'],
                'Xml' => $xml
            ];
        } else if ($estado == 2) {
            // vamos a interrogar a Hacienda
            $id = ltrim(substr($clave, 9, 12), '0');
            $token = new Token($id, $this->container);
            $token = $token->getToken();
            $client = new Client(
                ['headers' => ['Authorization' => 'bearer ' . $token]]
            );
            $sql  = 'SELECT Ambientes.URI_API '.
                        'FROM Ambientes '.
                        'LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente '.
                        'WHERE Empresas.Cedula=' . $id;
            $url = $db->query($sql)->fetch_assoc()['URI_API'] . "recepcion/$clave";

            $res = $client->request('GET', $url);
            if ($res->getStatusCode() == 200) {
                $body = $res->getBody();
                return $this->procesarMensajeHacienda($body);
            } else {
                // ocurrio un error
                return false;
            }
            
        } else if ($estado == 1) {
            // ni siquiera se ha enviado
            return [
                'Estado' => 'pendiente',
                'Mensaje' => ''
            ];
        }

    }

    /**
     * Coger el xml de un comprobante
     * 
     * @param string $clave La clave del comprobante
     * 
     * @return string El contenido del archivo xml
     */
    public function cogerXmlComprobante($clave) 
    {
        $db = $this->container['db'];
        $sql = "SELECT xmlFirmado
        FROM Emisiones
        WHERE Clave='$clave'";
        $xml = gzuncompress($db->query($sql)->fetch_assoc()['xmlFirmado']);
        return $xml;
    }

    /**
     * Analizar xml de comprobante
     * 
     * @param string $xml El xml a analizar
     * 
     * @return array La informacion del xml
     */
    public function analizarComprobante($xml)
    {
        //Eliminar la firma
        $xml = preg_replace("/.ds:Signature[\s\S]*ds:Signature./m", '', $xml);

        //Coger el elemento root del comprobante
        $s = stripos($xml, '<', 10) + 1;
        $e = stripos($xml, ' ', $s);
        $root = substr($xml, $s, $e - $s);

        //Coger el namespace del comprobante
        $s = stripos($xml, 'xmlns=') + 7;
        $e = stripos($xml, '"', $s+10);
        global $ns;
        $ns = substr($xml, $s, $e - $s);
        $xmlns = '{'.$ns.'}';

        $service = new Service;

        $f_keyValue = function (\Sabre\Xml\Reader $reader) {
            return \Sabre\Xml\Deserializer\keyValue($reader, $GLOBALS['ns']);
        };
        $f_repeatingElements = function (\Sabre\Xml\Reader $reader) {
            return \Sabre\Xml\Deserializer\repeatingElements($reader, $GLOBALS['ns']);
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
        return $service->parse($xml);
    }
}
