<?php
/**
 * Interfaz para procesar los comprobantes electronicos
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

use \Nekman\LuhnAlgorithm\Number;
use \GuzzleHttp\Client;
use function GuzzleHttp\json_encode;

/**
 * Class providing functions to manage electronic invoices
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion\Comprobantes
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class Comprobante
{
    protected $container;
    protected $id;
    protected $consecutivo;
    protected $clave;
    protected $tipo;
    protected $datos;

    /**
     * Constructor for the Comprobantes
     * 
     * @param array $container El contenedor con los ajustes
     * @param array $datos     Los datos del comprobante a crear
     */
    public function __construct($container, $datos)
    {
        $empresas = new Empresas($container);
        date_default_timezone_set('America/Costa_Rica');
        $id = $datos['Emisor']['Identificacion']['Numero'];
        if (!$empresas->exists($id)) {
            throw new \Exception('El emisor no esta registrado');
        };
        $this->id = $id;
        $this->container = $container;
        $this->container['id'] = $id;
        $this->consecutivo = $datos['NumeroConsecutivo'];
        $this->tipo = substr($this->consecutivo, 8, 2);
        $clave = $this->_generarClave();
        echo 'Clave: ' . $clave . "\n";//-----------------
        $this->clave = $clave;
        $this->datos = array_merge(['Clave' => $clave], $datos);
    }

    /**
     * Procesador de envios
     * 
     * @return bool
     */
    public function enviar()
    {
        $datos = $this->datos;
        $db = $this->container['db'];
        $creadorXml = new CreadorXML($this->container);
        $xml = $creadorXml->crearXml($datos);
        // Guardar el comprobante
        $xmldb = $db->real_escape_string($xml);
        $cl = $db->real_escape_string($this->clave);
        $sql = "INSERT INTO Emisiones ".
        "(Clave, Cedula, Estado, xmlFirmado) VALUES ".
        "(" . $cl . ", " . $this->id . ", ". 
        "1" . ", '" . $xmldb . "')";
        $db->query($sql);
        echo $db->error;
        // Enviar el comprobante a Hacienda
        $post = [
            'clave' => $datos['Clave'],
            'fecha' => $datos['FechaEmision'],
            'emisor' => [
                'tipoIdentificacion' => $datos['Emisor']['Identificacion']['Tipo'],
                'numeroIdentificacion' => $datos['Emisor']['Identificacion']['Numero']
            ],
            'receptor' => [
                'tipoIdentificacion' => $datos['Receptor']['Identificacion']['Tipo'],
                'numeroIdentificacion' => $datos['Receptor']['Identificacion']['Numero']
            ],
            'comprobanteXml' => base64_encode($xml)
        ];
        $token = new Token($this->id, $this->container);
        $token = $token->getToken();
        $sql  = 'SELECT Ambientes.URI_API '.
            'FROM Ambientes '.
            'LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente '.
            'WHERE Empresas.Cedula = ' . $this->id;
        $uri = $db->query($sql)->fetch_assoc()['URI_API'];
        echo $uri."\n";
        $client = new Client(['headers' => ['Authorization' => 'bearer ' . $token]]);
        echo 'Listo para hacer el post. ';
        /*
        echo json_encode($post);
        $res = $client->post($uri, ['json' => $post]);
        echo 'Respuesta: ' . $res->getStatusCode();
        return $xml;*/
        $header = array(
            'Authorization: bearer ' . $token,
            'Content-Type: application/json',
        );

        $curl = curl_init($uri);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($post));
        utf8_encode(curl_exec($curl));

        echo curl_getinfo($curl, CURLINFO_RESPONSE_CODE)."\n";
    }

    /**
     * Coger la clave
     * 
     * @return string La clave de este comprobante
     */
    public function cogerClave()
    {
        return $this->clave;
    }

    /**
     * Generador de clave numerica
     * 
     * @return string La clave numerica
     */
    private function _generarClave()
    {
        $pais = '506';
        $fecha = date('dmy');
        $cedula = str_pad($this->id, 12, '0', STR_PAD_LEFT);
        $consecutivo = $this->consecutivo;
        $estado = '1';
        $codigo = '99999999';
        $clave =  $pais . $fecha . $cedula . $consecutivo . $estado . $codigo;
        if (!(strlen($clave) === 50)) {
            throw new \Exception('La clave no tiene la correcta longitud');
        }
        return $clave;
    }
}