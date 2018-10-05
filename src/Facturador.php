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
            'callbackUrl' => '',
            'callbackUrlRecepcion' => ''
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
            'callbackUrl' => $config['callbackUrl'],
            'callbackUrlRecepcion' => $config['callbackUrlRecepcion']
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
        $ind_estado = strtolower($cuerpo['ind-estado']);
        if (($ind_estado == 'recibido') || ($ind_estado == 'procesando')) {
            $ind_estado = 'enviado';
        }
        $clave = substr($cuerpo['clave'], 0, 50);
        if ($this->estadoComprobanteRecibido($clave) == false) {
            $table = 'Emisiones';
        } else {
            $table = 'Recepciones';
        }
        if ($ind_estado == 'aceptado' || $ind_estado == 'rechazado') {
            // Procesar el xml enviado
            $xml = base64_decode($cuerpo['respuesta-xml']);
            /*$file = fopen(__DIR__ . "/respuesta.xml", "w");
            fwrite($file, $xml);
            fclose($file);*/
            $estado = ($ind_estado == 'aceptado') ? 3 : 4; //aceptado : rechazado
            $xmldb = $db->real_escape_string(gzcompress($xml, 9));
            $sql = "UPDATE $table 
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
     * Recibir xml de un proveedor
     * 
     * @param string $receptor        [tipo, id] del receptor del comprobante
     * @param int    $mensajeReceptor El consecutivo del mensaje
     * @param string $xmlInput        El xml para confirmar
     * 
     * @return int|bool El estado (-1: existe, 1:pendiente, 2:enviado)
     */
    public function recibirXml($receptor, $mensajeReceptor, $xmlInput)
    {
        $db = $this->container['db'];
        $clave = $mensajeReceptor['Clave'];
        if ($this->estadoComprobanteRecibido($clave) == false) {            
            $estado = 1; //pendiente
            $id = $receptor['id'];

            //Guardamos el xml
            $xmldb = $db->real_escape_string(gzcompress($xmlInput));
            $sql = "INSERT INTO Recepciones
                        (Clave, Cedula, Estado, xmlRecibido) 
                    VALUES
                        ('$clave', '$id', '$estado', '$xmldb')";
            $r = $db->query($sql);
            //Enviamos el mensaje de respuesta
            $container = $this->container;
            $container['receptor'] = $receptor; //Se necesita para mensaje Receptor
            $container['xmlData'] = $this->analizarComprobante($xmlInput);
            $comprobante = new Comprobante($container, $mensajeReceptor);
            return $comprobante->enviar(); //El estado, si fue exitoso, o false
        } else {
            return -1; //ya existe
        }
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
        $res = $db->query($sql);
        if ($res) {
            $res = $res->fetch_assoc();
            return $res['Estado'];
        } else {
            return false; //La clave no existe en la base de datos
        }
    }

    /**
     * Revisar estado de comprobante
     * 
     * @param string $clave La clave del comprobante
     * 
     * @return int El estado (1:pendiente, 2:enviado, 3: aceptado, 4:rechazado)
     */
    function estadoComprobanteRecibido($clave) 
    {   
        $db = $this->container['db'];
        $sql = "SELECT Estado
                FROM Recepciones
                WHERE Clave='$clave'";
        $res = $db->query($sql);
        if ($res) {
            $res = $res->fetch_assoc();
            return $res['Estado'];
        } else {
            return false; //La clave no existe en la base de datos
        }
        
        
    }

    /**
     * Interrogar estado de comprobante en Hacienda
     * 
     * @param string $clave La clave del comprobante a interrogar 
     * @param int    $lugar 1 para Emisiones, 2 para Recepciones
     * 
     * @return array El resultado
     */
    public function interrogarRespuesta($clave, $lugar)
    {
        $db = $this->container['db'];
        $consecutivo = false;
        if ($lugar == 1) {
            $estado = $this->estadoComprobante($clave);
            $table = 'Emisiones';
        } else if ($lugar == 2) {
            $table = 'Recepciones';
            $estado = $this->estadoComprobanteRecibido($clave);
            $xml = $this->cogerXmlConfirmacion($clave);
            if ($xml) {
                $consecutivo = $this->analizarComprobante($xml)['NumeroConsecutivoReceptor'];
            } else {
                return false;
            }
        } else {
            return false;
        }
        if ($estado === false) {
            return false;
        }
        if ($estado == 5) {
            $sql = "SELECT msg
            FROM $table
            WHERE Clave='$clave'";
            $msg = $db->query($sql)->fetch_assoc()['msg'];
            return [
                'Clave' => $data['Clave'],
                'Estado' => 'error',
                'Mensaje' => $msg,
            ];
        } else if ($estado > 2) {
            //ya tenemos la respuesta de Hacienda en la base de datos
            $estado = ($estado == 3) ? 'aceptado' : 'rechazado'; //aceptado : rechazado
            $sql = "SELECT Respuesta
            FROM $table
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
            $sql = "SELECT Cedula FROM $table WHERE Clave='$clave'";
            $id = $db->query($sql)->fetch_assoc()['Cedula'];
            $token = new Token($id, $this->container);
            $token = $token->getToken();
            if ($token) {
                $client = new Client(
                    ['headers' => ['Authorization' => 'bearer ' . $token]]
                );
                $sql  = 'SELECT Ambientes.URI_API '.
                            'FROM Ambientes '.
                            'LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente '.
                            'WHERE Empresas.Cedula=' . $id;
                $url = $db->query($sql)->fetch_assoc()['URI_API'] . "recepcion/$clave";
                if ($consecutivo) {
                    $url .= "-$consecutivo";
                }
                try {
                    $res = $client->request('GET', $url);
                    if ($res->getStatusCode() == 200) {
                        $body = $res->getBody();
                        return $this->procesarMensajeHacienda($body);
                    } else {
                        // ocurrio un error
                        return false;
                    }
                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    $res = $e->getResponse();
                    $mensaje = $res->getHeader('X-Error-Cause')[0];
                    if (strrpos($msg, "no ") > 1) {
                        //El comprobante no ha sido enviado
                        $estado = 1;
                        $est = "enviado";
                    } else {
                        $estado = 5; //error
                        $est = "error";
                    }
                    $sql = "UPDATE $table 
                        SET Estado=$estado, msg='$mensaje' 
                        WHERE Clave='$clave'";
                    $db->query($sql);
                    return [
                        'Clave' => $data['Clave'],
                        'Estado' => $est,
                        'Mensaje' => $mensaje,
                    ];
                }
                
            }
            // No se pudo actualizar
            $estados = ['pendiente', 'enviado', 'aceptado', 'rechazado', 'error'];
            $estado = $estados[$estado - 1];
            return [
                'Clave' => $clave,
                'Estado' => $estado,
                'Mensaje' => ''
            ];
        } else if ($estado == 1) {
            // ni siquiera se ha enviado
            $this->enviarPendientes($clave);
            if ($lugar == 1) {
                $estado = $this->estadoComprobante($clave);
            } else if ($lugar == 2) {
                $estado = $this->estadoComprobanteRecibido($clave);
            }
            $estado = array('pendiente', 'enviado', 'aceptado', 'rechazado', 'error')[$estado - 1];
            return [
                'Clave' => $clave,
                'Estado' => $estado,
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
     * Coger el xml de confirmacion de un comprobante recibido
     * 
     * @param string $clave La clave del comprobante recibido
     * 
     * @return string El contenido del archivo xml de confirmacion
     */
    public function cogerXmlConfirmacion($clave) 
    {
        $db = $this->container['db'];
        $sql = "SELECT xmlConfirmacion
        FROM Recepciones
        WHERE Clave='$clave'";
        $xml = gzuncompress($db->query($sql)->fetch_assoc()['xmlConfirmacion']);
        return $xml;
    }

    /**
     * Coger el msg de un comprobante
     * 
     * @param string $clave La clave del comprobante recibido
     * @param int    $lugar 1 para Emisiones, 2 para Recepciones
     * 
     * @return string El contenido del archivo xml de confirmacion
     */
    public function cogerMsg($clave, $lugar) 
    {
        if ($lugar == 1) {
            $table = 'Emisiones';
        } else if ($lugar == 2) {
            $table = 'Recepciones';
        } else {
            return false;
        }
        $db = $this->container['db'];
        $sql = "SELECT msg
        FROM $table
        WHERE Clave='$clave'";
        $msg = $db->query($sql)->fetch_assoc()['msg'];
        return $msg;
    }

    /**
     * Coger el xml de respuesta de un comprobante
     * 
     * @param string $clave La clave del comprobante
     * @param int    $lugar 1 para Emisiones, 2 para Recepciones
     * 
     * @return string El contenido del mensaje de respuesta
     */
    public function cogerXmlRespuesta($clave, $lugar) 
    {
        if ($lugar == 1) {
            $table = 'Emisiones';
        } else if ($lugar == 2) {
            $table = 'Recepciones';
        } else {
            return false;
        }
        $db = $this->container['db'];
        $sql = "SELECT Respuesta
        FROM $table
        WHERE Clave='$clave'";
        $xml = gzuncompress($db->query($sql)->fetch_assoc()['Respuesta']);
        return $xml;
    }

    /**
     * Enviar los comprobantes pendientes en la base de datos
     * 
     * @param int $clupd La clave del que queremos actualizar, nada si mandar todo
     * 
     * @return array Clave => lugar con todos los que se enviaron
     */
    public function enviarPendientes($clupd = false) 
    {
        if ($clupd) {
            $select = " AND Clave='$clupd'";
        } else {
            $select = '';
        }

        $db = $this->container['db'];
        $tables = ['Emisiones', 'Recepciones'];
        $enviados = [];

        foreach ($tables as $table) {
            $lugar = $table == "Emisiones" ? 1 : 2;
            $sqlr = "SELECT Clave, Cedula, Respuesta
                    FROM $table
                    WHERE Estado='1'$select
                    LIMIT 1";
            do {
                $query = $db->query($sqlr);
                if (is_object($query)) {
                    $row = true;
                    $r = $query->fetch_assoc();
                    //Volvemos a enviar el comprobante
                    $clave = $r['Clave'];
                    $savedPost = $r['Respuesta'];
                    $post = json_decode($savedPost, true);//El post que fue guardado
                    $id = $r['Cedula'];
                    $estado = 1;
                    $tokens = new Token($id, $this->container);
                    $token = $tokens->getToken();
                    $msg = 'pendiente';
                    if ($token && $savedPost) {
                        // Hacer un envio solamente si logramos recibir un token
                        // y el post se habia guardado
                        $sql  = "SELECT Ambientes.URI_API
                        FROM Ambientes
                        LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente
                        WHERE Empresas.Cedula = $id";
                        $uri = $db->query($sql)->fetch_assoc()['URI_API'] . 'recepcion';
                        $client = new Client(
                            ['headers' => ['Authorization' => 'bearer ' . $token]]
                        );
                        try {
                            $res = $client->post($uri, ['json' => $post]);
                            $code = $res->getStatusCode();
                            if ($code == 201 || $code == 202) {
                                $estado = 2; //enviado
                                $msg = '';
                                $enviados[$clave] = $lugar;
                            } else {
                                $msg = "Codigo: " . $code;
                            }
                        } catch (\GuzzleHttp\Exception\ClientException $e) {
                            // a 400 level exception occured
                            // cuando ocurre este error, el comprobante se guarda 
                            // con el estado 5 para error, junto con el mensaje en msg.
                            $res = $e->getResponse();
                            $msg = $res->getStatusCode() . ": ";
                            $msg .= $res->getHeader('X-Error-Cause')[0];
                            if (strrpos($msg, "ya") > 1) {
                                //El comprobante ya se habia enviado
                                $estado = 2;
                                $msg = '';
                            } else {
                                $estado = 5; //error
                            }
                        } catch (\GuzzleHttp\Exception\ConnectException $e) {
                            //No se pudo enviar
                            $msg = "Sin conexión.";
                            $row = false;                           
                        } catch (\GuzzleHttp\Exception\ServerException $e) {
                            //No se pudo enviar
                            $msg = "Error de servidor.";
                            $row = false;                           
                        }
                    } else {
                        $msg = "Fallo en coger Token";
                    }
                    if ($estado == 1) {
                        if ($token) {
                            //No se pudo enviar cuando teniamos el token.
                            //Dejamos de tratar.
                            $row = false;
                        } else {
                            //Error al coger token
                            //temporalmente lo deshabilitamos
                            $estado = 9;
                        }
                    }
                    //Guardar el resultado cuando se ha actualizado
                    $sql = "UPDATE $table SET
                        Estado='$estado',
                        msg='$msg'
                        WHERE Clave='$clave'";
                    $db->query($sql);
                } else {
                    //No hay mas pendientes
                    $row = false;
                    //Volvemos los que no se enviaron a pendiente
                    $sql = "UPDATE $table SET
                        Estado='1'
                        WHERE Estado='9'";
                    $db->query($sql);
                }
            } while ($row == true);
        }
        return $enviados;
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
        if ($xml == false) {
            //No enviaron nada, entonces solo daria error
            return false;
        }
        //Eliminar la firma
        $xml = preg_replace("/.ds:Signature[\s\S]*ds:Signature./m", '', $xml);
        $xml = preg_replace("/\<\/Tipo\>[\s]*\<Codigo\>/", '</Tipo><Codi>', $xml);
        $xml = preg_replace("/\<\/Codigo\>[\s]*\<\/Codigo>/", '</Codi></Codigo>', $xml);
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
            $service->elementMap[$xmlns.'DetalleServicio'] = function (\Sabre\Xml\Reader $reader) {
                return \Sabre\Xml\Deserializer\repeatingElements($reader, $GLOBALS['xmlns'].'LineaDetalle');
            };
        } else {
            $service->elementMap[$xmlns.'LineaDetalle'] = $f_keyValue;
        }
        return $service->parse($xml);
    }
}
