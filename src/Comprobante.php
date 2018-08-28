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
use \Nekman\LuhnAlgorithm\LuhnAlgorithmFactory;
use \GuzzleHttp\Client;
use \GuzzleHttp\Exception;
use \GuzzleHttp\Psr7;

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
    protected $id; //cedula de empresa
    protected $consecutivo; // consecutivo de comprobante
    protected $clave; // clave generada por este sistema
    protected $tipo; // tipo de comprobante
    protected $datos; // la informacion del comprobante
    protected $situacion; // 1=Normal, 2=Contingencia, 3=Sin Internet
    protected $estado; // el estado: 1=Pendiente, 2=Enviado, 3=Aceptado 4=Rechazado 5=Error

    /**
     * Constructor for the Comprobantes
     * 
     * @param array $container   El contenedor con los ajustes
     * @param array $datos       Los datos del comprobante a crear
     * @param bool  $sinInternet True para un comprobante que se genero sin conexion
     */
    public function __construct($container, $datos, $sinInternet = false)
    {
        date_default_timezone_set('America/Costa_Rica');
        if (isset($datos['NumeroConsecutivoReceptor'])) {
            //Este es un mensaje de confirmacion
            $id = ltrim($datos['NumeroCedulaReceptor'], '0');
            $clave = $datos['Clave'];
            $consecutivo = $datos['NumeroConsecutivoReceptor'];
        } else {
            //Es una factura
            $id = $datos['Emisor']['Identificacion']['Numero'];
            $consecutivo = $datos['NumeroConsecutivo'];
        }
        
        $empresas = new Empresas($container);
        if (!$empresas->exists($id)) {
            throw new \Exception('El emisor no esta registrado');
        };
        $this->id = $id;
        $this->container = $container;
        $this->container['id'] = $id;
        $this->consecutivo = $consecutivo;
        $this->tipo = substr($consecutivo, 8, 2);
        if ($sinInternet) {
            $this->situacion = 3; //Sin internet
        } else {
            $this->situacion = 1; //Normal
        }
        if (!isset($datos['Clave'])) {
            $clave = $this->_generarClave();
            $datos = array_merge(['Clave' => $clave], $datos);
        }
        $this->datos = $datos;    
        if (isset($datos['InformacionReferencia'])) {
            if ($datos['InformacionReferencia']['TipoDoc'] == '08') {
                $this->situacion = 2; //Contingencia
            }
        }
        $this->estado = 1; //Pendiente
        //echo 'Clave: ' . $clave . "\n";//-----------------
        $this->clave = $clave;
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

        if ($this->tipo <= 4) {
            //Cogemos datos para una factura
            $post = [
                'clave' => $datos['Clave'],
                'fecha' => $datos['FechaEmision'],
                'emisor' => [
                    'tipoIdentificacion' => $datos['Emisor']['Identificacion']['Tipo'],
                    'numeroIdentificacion' => $datos['Emisor']['Identificacion']['Numero']
                ]
            ];
            if (isset($datos['Receptor']['Identificacion'])) {
                $post['receptor'] = [
                    'tipoIdentificacion' => $datos['Receptor']['Identificacion']['Tipo'],
                    'numeroIdentificacion' => $datos['Receptor']['Identificacion']['Numero']
                ];
            }
        } else {
            //Cogemos datos para un mensaje receptor
            $xmlData = $this->container['xmlData'];
            $receptor = $this->container['receptor'];
            $tipoId = str_pad($receptor['tipo'], 2, '0', STR_PAD_LEFT);
            $post = [
                'clave' => $xmlData['Clave'],
                'fecha' => $xmlData['FechaEmision'],
                'emisor' => [
                    'tipoIdentificacion' => $xmlData['Emisor']['Identificacion']['Tipo'],
                    'numeroIdentificacion' => $xmlData['Emisor']['Identificacion']['Numero']
                ],
                'receptor' => [
                    'tipoIdentificacion' => $tipoId,
                    'numeroIdentificacion' => $receptor['id']
                ]
            ];
        }
        // Enviar el comprobante a Hacienda
        
        $callbackUrl = $this->container['callbackUrl'];
        if ($callbackUrl) {
             $post['callbackUrl'] = $callbackUrl;
        }
        if ($this->tipo > 4) {
            $post['consecutivoReceptor'] = $datos['NumeroConsecutivoReceptor'];
        }
        $post['comprobanteXml'] = base64_encode($xml);
       
        $token = new Token($this->id, $this->container);
        $token = $token->getToken();
        $estado = 1; //Pendiente
        $msg = '';
        $json = '';
        if ($token) {
            // Hacer un envio solamente si logramos recibir un token
            $sql  = "SELECT Ambientes.URI_API
            FROM Ambientes
            LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente
            WHERE Empresas.Cedula = $this->id";
            $uri = $db->query($sql)->fetch_assoc()['URI_API'] . 'recepcion';
            //echo "\nURL: $uri \n";
            $client = new Client(
                ['headers' => ['Authorization' => 'bearer ' . $token]]
            );
            //echo "Listo para hacer el post.\n\n";

            try {
                $res = $client->post($uri, ['json' => $post]);
                $code = $res->getStatusCode();
                //echo "\nRespuesta: $code\n";
                if ($code == 201 || $code == 202) {
                    $this->estado = 2; //enviado
                }
            } catch (Exception\ClientException $e) {
                // a 400 level exception occured
                // cuando ocurre este error, el comprobante se guarda 
                // con el estado 5 para error, junto con el mensaje en msg.
                $res = $e->getResponse();
                $msg = $res->getStatusCode() . ": ";
                $msg .= json_encode($res->getHeader('X-Error-Cause'));
                $this->estado = 5; //error

                //echo Psr7\str($res);
                //echo 'Respuesta: ' . ."\n";
            } catch (Exception\ConnectException $e) {
                // a connection problem
                // Guardamos la informacion del post para enviarlo posteriormente
                $json = $db->real_escape_string(json_encode($post));
                
            };
        }
     
        // Guardar el comprobante
        /*$file = fopen(__DIR__ . "/msg.xml", "w");
        fwrite($file, $xml . "\nRespuesta: $code\n" . Psr7\str($res));
        fclose($file);*/
        $xmldb = $db->real_escape_string(gzcompress($xml));
        $cl = $this->clave;
        if ($this->tipo <= 4) {
            //Guardamos la factura
            $sql = "INSERT INTO Emisiones
                (Clave, Cedula, Estado, msg, xmlFirmado, Respuesta) VALUES
                ('$cl', '$this->id', '$this->estado', '$msg', '$xmldb', '$json')";
                $db->query($sql);
            return $cl;
        } else {
            //Guardamos el mensaje de confirmacion
            $sql = "UPDATE Recepciones
                        SET xmlConfirmacion='$xmldb',
                            Estado='$this->estado',
                            msg='$msg',
                            Respuesta='$json'
                        WHERE Clave='$cl'";
            $db->query($sql);
            return $this->estado;
        }
        //echo $db->error."\n";
        
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
        $luhn = LuhnAlgorithmFactory::create();
        $pais = '506';
        $fecha = date('dmy');
        $cedula = str_pad($this->id, 12, '0', STR_PAD_LEFT);
        $consecutivo = $this->consecutivo;
        $situacion = $this->situacion;
        $codigo = $luhn->calcCheckDigit(new Number($pais));
        $codigo .= $luhn->calcCheckDigit(new Number($fecha));
        $codigo .= $luhn->calcCheckDigit(new Number($cedula));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, 0, 3)));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, 3, 5)));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, 8, 2)));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, -10)));
        $codigo .= $luhn->calcCheckDigit(new Number($situacion));
        $clave =  $pais . $fecha . $cedula . $consecutivo . $situacion . $codigo;
        if (!(strlen($clave) === 50)) {
            throw new \Exception('La clave no tiene la correcta longitud');
        }
        return $clave;
    }
}