<?php
/**
 * Facturador electronico para Costa Rica
 * 
 * Este componente suple una interfaz para la integración de facturación
 * electrónica con el Ministerio de Hacienda en Costa Rica
 * 
 * PHP version 7.2
 * 
 * @category  Facturacion-electronica
 * @package   Contica\FacturadorElectronico
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturacion-electronica-cr
 */

namespace Contica\Facturacion;

use \Defuse\Crypto\Key;
use \Sabre\Xml\Service;
use \GuzzleHttp\Client;
use \Monolog\Logger;

/**
 * El proveedor de facturacion
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class FacturadorElectronico
{
    protected $container;
    
    /**
     * Invoicer constructor
     * 
     * @param MySqli $db       Conexion a MySql, conectado a la tabla correspondiente
     * @param array  $settings Llave para utilizar en encriptado de datos en la BD
     */
    public function __construct($db, $settings = [])
    {
        // Initialize the container
        $container = array_merge([
            'crypto_key' => '',
            'client_id' => 0,
            'storage_path' => '',
            'callback_url' => ''
        ], $settings);

        $crypto_key = $container['crypto_key'];
        $container['crypto_key'] = $crypto_key ? Key::loadFromAsciiSafeString($crypto_key) : '';
        $container['db'] = $db;

        // Inicializar el logger
        $loglevel = Logger::INFO;
        $log = new Logger('facturador');
        $log->pushHandler(new MySqlLogger($db, $loglevel));
        $container['log'] = $log;
        $container['rate_limiter'] = new RateLimiter($container);

        $this->container = $container;
    }

    /**
     * Especificar el id de cliente despues de crear objeto
     * 
     * @return null
     */
    public function setClientId($id)
    {
        $this->container['client_id'] = $id;
    }

    /**
     * Crear llave de encriptacion de base de datos
     * 
     * @return string La representacion en texto de la llave
     */
    public static function crearLlaveSeguridad()
    {
        $key = Key::createNewRandomKey();
        return $key->saveToAsciiSafeString();
    }

    /**
     * Crear o modificar una empresa
     *
     * @param array $datos Los datos de la empresa
     * @param int   $id    El ID de la empresa cuando se va a modificar
     *
     * @return int El ID unico de la empresa creada
     */
    public function guardarEmpresa($datos, $id = 0)
    {
        $empresas = new Empresas($this->container);
        $log = $this->container['log'];
        if ($id === 0) {
            $log->notice('Creando empresa nueva para el cliente con ID ' . $this->container['client_id']);
            return $empresas->add($datos);
        } else {
            $log->info("Modificando empresa $id para el cliente con ID " . $this->container['client_id']);
            return $empresas->modify($id, $datos);
        }
    }

    /**
     * Coger los datos de una empresa
     * 
     * @param int $id ID unico de la empresa
     * 
     * @return array Todos los campos de texto de la empresa
     */
    public function cogerEmpresa($id)
    {
        $empresas = new Empresas($this->container);
        return $empresas->get($id);
    }

    /**
     * Coger los datos basicos de todas las empresas
     * 
     * @return array (id, cedula) de todas las empresas
     */
    public function cogerEmpresas()
    {
        $empresas = new Empresas($this->container);
        return $empresas->get();
    }

    /**
     * Buscar el ID de las empresas con cierta cedula
     * 
     * @param int $cedula La cedula de la empresa
     * 
     * @return array Array con los IDs de las empresas con la cedula provista
     */
    public function buscarEmpresaPorCedula($cedula)
    {
        $empresas = new Empresas($this->container);
        return $empresas->buscarPorCedula($cedula);
    }

     /**
     * Coger la llave criptografica de la empresa
     * 
     * @param int $id ID unico de la empresa
     * 
     * @return file La llave criptografica de la empresa
     */
    public function cogerLlaveCriptograficaEmpresa($id)
    {
        $empresas = new Empresas($this->container);
        return $empresas->getCert($id);
    }

    /**
     * Enviar compropbante al Ministerio de Hacienda
     * 
     * @param array $datos Los datos para construir el comprobante para enviar
     * @param int   $id    El ID unico de la empresa emisora
     * 
     * @return string La clave numerica del comprobante
     */
    public function enviarComprobante($datos, $id, $sinInternet = false )
    {
        $comprobante = new Comprobante($this->container, $datos, $id, $sinInternet);
        if ($comprobante->guardarEnCola()) {
            return $comprobante->clave;
        } else {
            return false;
        }  
    }

    /**
     * Procesar el callback de Hacienda
     * 
     * @param string $cuerpo El cuerpo del POST de Hacienda
     * @param int    $token  Token de identificacion
     * 
     * @return array Estado del comprobante enviado
     */
    public function procesarCallbackHacienda($cuerpo, $token = '')
    {
        $log = $this->container['log'];
        $cuerpo = json_decode($cuerpo, true);
        $ind_estado = strtolower($cuerpo['ind-estado']);
        $clave = substr($cuerpo['clave'], 0, 50);
        if ($token === '') {
            if (isset($_REQUEST['token'])) {
                //Conseguir el token del POST
                $token = $_REQUEST['token'];
            } else {
                $log->error("Error al procesar callback de Hacienda para la clave $clave. No hay token de identificacion.");
                throw new \Exception("No existe token para procesar el mensaje de Hacienda para la clave $clave");
            }
        }
        $db = $this->container['db'];

        //Verificar la validez del token
        $tipo = strtoupper(substr($token, 0, 1));
        if ($tipo == 'E') {
            //Emision
            $valido = true;
            $tabla = 'fe_emisiones';
            $col = 'id_emision';
        } elseif ($tipo == 'R') {
            //Recepcion
            $valido = true;
            $tabla = 'fe_recepciones';
            $col = 'id_recepcion';
        } else {
            $valido = false;
        }

        if ($valido) {
            //Validar el id
            $sql = "SELECT id_empresa, COUNT(*) FROM $tabla WHERE $col=? AND clave=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('is', substr($token, 1), $clave);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_row();
            $id_empresa = $r[0];
            $valido = $r[1] > 0;
        }

        if (!$valido) {
            $log->error("Error al procesar callback de Hacienda para la clave $clave. Token invalido.");
            throw new \Exception("Token invalido al procesar callback de Hacienda para la clave $clave");
        }

        $log->debug("Guardando mensaje de respuesta de Hacienda. Clave: $tipo$clave");
        $datos = [
            'clave' => $clave,
            'tipo' => $tipo
        ];
        $xml = base64_decode($cuerpo['respuesta-xml']);
        $comprobante = new Comprobante($this->container, $datos, $id_empresa);
        $comprobante->guardarMensajeHacienda($xml);
        return [
            'Clave' => $clave,
            'Estado' => $ind_estado,
            'Mensaje' => $comprobante->cogerDetalleMensaje(),
            'Xml' => $xml
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
     * Coger el xml de un comprobante recibido
     * 
     * @param string $clave La clave del comprobante recibido
     * 
     * @return string El contenido del archivo xml recibido
     */
    public function cogerXmlRecepcion($clave) 
    {
        $db = $this->container['db'];
        $sql = "SELECT xmlRecibido
        FROM Recepciones
        WHERE Clave='$clave'";
        $xml = gzuncompress($db->query($sql)->fetch_assoc()['xmlRecibido']);
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
     * Enviar los comprobantes en la cola de envio
     * 
     * @return array [clave => clave, tipo => E o R] con todos los que se enviaron
     */
    public function enviarCola() 
    {
        $db = $this->container['db'];
        $timestamp = (new \DateTime())->getTimestamp(); //tiempo actual
        $sql = "SELECT * FROM fe_cola WHERE tiempo_enviar <= $timestamp AND accion < 3";
        $res = $db->query($sql);
        $enviados = [];
        while ($row = $res->fetch_assoc()) {
            $datos = [
                'clave' => $row['clave'],
                'tipo' => $row['accion'] == 1 ? 'E' : 'R'
            ];
            $comprobante = new Comprobante($this->container, $datos, $row['id_empresa']);
            if ($comprobante->enviar()) {
                $enviados[] = $datos;
            }
        }
        return $enviados;
    }
}
