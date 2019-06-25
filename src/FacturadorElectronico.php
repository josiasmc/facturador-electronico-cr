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
        $loglevel = Logger::DEBUG;
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
            'clave' => $clave,
            'tipo' => $tipo,
            'estado' => $ind_estado,
            'mensaje' => $comprobante->cogerDetalleMensaje(),
            'xml' => $xml //XML de respuesta de hacienda
        ];
    }

    /**
     * Recibir xml de un proveedor
     * 
     * @param string $xml         XML que se esta recibiendo
     * @param int    $datos       Datos del comprobante de recepcion
     * @param string $id_empresa  ID de la empresa receptora
     * 
     * @return int|bool El estado
     */
    public function recepcionar($xml = '', $datos, $id_empresa)
    {
        //Guardar el XML recepcionado si se envio
        if ($xml) {
            $clave = $datos['Clave'];
            $path = $this->container['storage_path'];
            $path .= "$id_empresa/";
            if (!file_exists($path)) {
                mkdir($path);
            }
            $path .= "20" . \substr($clave, 7, 2) . \substr($clave, 5, 2) . '/';
            if (!file_exists($path)) {
                mkdir($path);
            }
            $tipo_doc = substr($clave, 30, 1) - 1;
            $tipo_doc = ['FE', 'NDE', 'NCE', 'TE', 'MR', 'MR', 'MR', 'FEC', 'FEE'][$tipo_doc];
            $filename = $tipo_doc . $clave . '.xml';
            $zip_name = 'R' . $clave . '.zip';
    
            $zip = new \ZipArchive();
            if ($zip->open($path . $zip_name, \ZipArchive::CREATE) !== true) {
                throw new \Exception("Fallo al abrir <$zip_name>\n");
            }
            $zip->addFromString($filename, $xml);
            $zip->close();
        }

        //Crear el comprobante de recepcion
        $comprobante = new Comprobante($this->container, $datos, $id_empresa);
        return $comprobante->guardarEnCola();
    }

    /**
     * Consultar el estado de un comprobante
     * 
     * @param string $clave      La clave del comprobante a interrogar 
     * @param int    $tipo       E para Emisiones, R para Recepciones
     * @param int    $id_empresa ID unico de empresa
     * 
     * @return array El resultado
     */
    public function consultarEstado($clave, $tipo, $id_empresa)
    {
        $db = $this->container['db'];
        $datos = [
            'clave' => $clave,
            'tipo' => $tipo
        ];
        $comprobante = new Comprobante($this->container, $datos, $id_empresa);
        $estado = $comprobante->estado;
        
        if ($estado > 2) {
            //ya tenemos la respuesta de Hacienda en la base de datos
            $xml = $comprobante->cogerXmlRespuesta();

        } else if ($estado == 2) {
            //comprobante esta enviado
            $comprobante->consultarEstado();
            $estado = $comprobante->estado;
            if ($xml = $comprobante->cogerXmlRespuesta()) {
                $xml = '';
            }

        } else if ($estado == 1) {
            // ni siquiera se ha enviado
            $comprobante->enviar();
            $estado = $comprobante->estado;
            $xml = '';
        }
        $estado = array('pendiente', 'enviado', 'aceptado', 'rechazado')[$estado - 1];
        return [
            'clave' => $clave,
            'estado' => $estado,
            'mensaje' => $comprobante->cogerDetalleMensaje(),
            'xml' => $xml //xml de confirmacion de Hacienda
        ];

    }

    /**
     * Coger XML
     * 
     * @param string $clave La clave del comprobante
     * @param string $lugar E o R
     * @param int    $tipo  Cual xml
     * @param int    $id    ID empresa
     * 
     * @return string Contenido del xml o false si no se halla
     */
    public function cogerXml($clave, $lugar, $tipo, $id)
    {
        //Revisar si concuerda clave con empresa
        $db = $this->container['db'];
        if ($lugar == 'E') {
            //Emision
            $tabla = 'fe_emisiones';
            $col = 'id_emision';
        } elseif ($lugar == 'R') {
            //Recepcion
            $tabla = 'fe_recepciones';
            $col = 'id_recepcion';
        } else {
            return false;
        }
        $sql = "SELECT estado FROM $tabla WHERE clave=$clave AND id_empresa=$id";
        $res = $db->query($sql);
        if ($res->num_rows > 0) {
            $estado = $res->fetch_row()[0];
            if (($tipo == 2 && $estado < 3 && $lugar == 'E')) {
                //No existe respuesta para emision
                return false;
            } elseif (($tipo == 4 && $estado < 3 && $lugar == 'R')) {
                //No existe respuesta para recepcion
                return false;
            }
            //Conseguir el archivo
            $storage_path = $this->container['storage_path'];
            $storage_path .= "$id/";
            $storage_path .= "20" . \substr($clave, 7, 2) . \substr($clave, 5, 2) . '/';

            $zip_name = $lugar . $clave . '.zip';
            if ($tipo == 2) {
                $tipo_doc = 'MH';
            } elseif ($tipo == 4) {
                $tipo_doc = 'MHMR';
            } else {
                $tipo_doc = substr($clave, 30, 1) - 1;
                $tipo_doc = ['FE', 'NDE', 'NCE', 'TE', 'MR', 'MR', 'MR', 'FEC', 'FEE'][$tipo_doc];
            }
            $filename = $tipo_doc . $clave . '.xml';

            $zip = new \ZipArchive();
            if ($zip->open($storage_path . $zip_name) !== true) {
                throw new \Exception("Fallo al abrir <$zip_name> abriendo MR\n");
            }

            if ($zip->locateName($filename) !== false) {
                //XML esta guardado
                $xml = $zip->getFromName($filename);
            } else {
                return false;
            }
            $zip->close();
            return $xml;

        } else {
            return false;
        }
    }

    /**
     * Coger el msg de un comprobante
     * 
     * @param string $clave La clave del comprobante recibido
     * @param int    $tipo  E para Emisiones, R para Recepciones
     * 
     * @return string El mensaje que Hacienda devolvio con el xml
     */
    public function cogerMsg($clave, $tipo) 
    {
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
            return false;
        }
        $db = $this->container['db'];
        $sql = "SELECT mensaje
        FROM $tabla
        WHERE clave='$clave'";
        $msg = $db->query($sql)->fetch_assoc()['mensaje'];
        return $msg;
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
