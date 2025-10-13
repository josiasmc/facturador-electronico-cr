<?php

/**
 * Facturador electronico para Costa Rica
 *
 * Este componente suple una interfaz para la integración de facturación
 * electrónica con el Ministerio de Hacienda en Costa Rica
 *
 * PHP version 7.4
 *
 * @package   Contica\FacturadorElectronico
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2025 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

use DateTime;
use Exception;
use Monolog\Logger;
use Aws\S3\S3Client;
use Defuse\Crypto\Key;
use Contica\Facturacion\Exceptions\XmlNotFoundException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\{FilesystemException, UnableToReadFile, UnableToWriteFile};

/**
 * El proveedor de facturacion
 *
 */
class FacturadorElectronico
{
    protected $container;

    /**
     * Invoicer constructor
     *
     * @param \mysqli $db       Conexion a MySql, conectado a la base de datos correspondiente
     * @param array   $settings Ajustes del facturador
     */
    public function __construct($db, $settings = [])
    {
        // Ajustes predeterminados
        $container = array_merge([
            'crypto_key' => '',       // Llave para utilizar en encriptado de datos en la BD
            'client_id' => 0,         // ID de empresa
            'storage_path' => '',     // Ruta para guardar los comprobantes
            'callback_url' => '',     // URL para Hacienda enviar las respuestas
            'storage_type' => 'local', // Lugar en que se guardan los comprobantes. 'local' | 's3',
            's3_client_options' => [], // Opciones para el cliente de S3
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

        // Crear la conexion al sistema de archivos
        if ($container['storage_type'] == 'local') {
            $storage_path = $container['storage_path'];
            if ($storage_path == '') {
                throw new Exception('Especifique la ruta de almacenaje para guardar comprobantes.');
            }
            $adapter = new LocalFilesystemAdapter($container['storage_path']);
        } elseif ($container['storage_type'] == 's3') {
            if (!is_array($container['s3_client_options'])) {
                throw new Exception('Error al conectarse al almacenaje S3. No se suministraron las opciones de la conexión.');
            }
            $s3ClientOptions = $container['s3_client_options'];
            $s3ClientOptions['suppress_php_deprecation_warning'] = true;
            $client = new S3Client($s3ClientOptions);
            if (!isset($container['s3_bucket_name'])) {
                throw new Exception('Error al conectarse al almacenaje S3. No se especificó el nombre del bucket.');
            }
            $adapter = new AwsS3V3Adapter($client, $container['s3_bucket_name']);
        }
        $filesystem = new \League\Flysystem\Filesystem($adapter);
        $container['filesystem'] = $filesystem;

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
     * @return array La llave criptografica de la empresa
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
    public function enviarComprobante($datos, $id, $sinInternet = false)
    {
        $comprobante = new Comprobante($this->container, $datos, $id, $sinInternet);
        if ($comprobante->guardarEnCola()) {
            return $comprobante->clave;
        }
        return false;
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
        if ($ind_estado == 'recibido' || $ind_estado == 'procesando') {
            $ind_estado = 'enviado';
        }
        $clave = substr($cuerpo['clave'], 0, 50);
        if ($token === '') {
            if (isset($_REQUEST['token'])) {
                //Conseguir el token del POST
                $token = $_REQUEST['token'];
            } else {
                $log->error("Error al procesar callback de Hacienda para la clave $clave. No hay token de identificacion.");
                throw new Exception("No existe token para procesar el mensaje de Hacienda para la clave $clave");
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
            if ($stmt === false) {
                throw new Exception('Error al preparar la consulta para validar el id de la emisión o recepción con clave ' . $clave);
            }
            $token = substr($token, 1);
            $stmt->bind_param('is', $token, $clave);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_row();
            $stmt->close();
            $id_empresa = $r[0];
            $valido = $r[1] > 0;
        }

        if (!$valido) {
            $log->error("Error al procesar callback de Hacienda para la clave $tipo$clave. Token invalido.");
            throw new Exception("Token invalido al procesar callback de Hacienda para la clave $tipo$clave");
        }

        $datos = [
            'clave' => $clave,
            'tipo' => $tipo,
        ];

        if (isset($cuerpo['respuesta-xml'])) {
            $log->debug("Guardando mensaje de respuesta de Hacienda. Clave: $tipo$clave");
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
        } else {
            return [
                'clave' => $clave,
                'tipo' => $tipo,
                'estado' => $ind_estado,
                'mensaje' => '',
                'xml' => '',
            ];
        }
    }

    /**
     * Recibir xml de un proveedor
     *
     * @param string $xml        XML que se esta recibiendo
     * @param array  $datos      Datos del comprobante de recepcion
     * @param string $id_empresa ID de la empresa receptora
     *
     * @return int|bool El estado
     */
    public function recepcionar($xml, $datos, $id_empresa)
    {
        //Guardar el XML recepcionado si se envia
        if ($xml) {
            $clave = $datos['Clave'];
            $filesystem = $this->container['filesystem'];
            $path = "$id_empresa/20" . substr($clave, 7, 2) . substr($clave, 5, 2) . '/';
            $tipo_doc = substr($clave, 29, 2) - 1;
            $tipo_doc = ['FE', 'NDE', 'NCE', 'TE', 'MR', 'MR', 'MR', 'FEC', 'FEE', 'REP'][$tipo_doc];
            $filename = "$tipo_doc$clave.xml";
            $zip_name = "R$clave.zip";

            $zip = new \ZipArchive();
            $tmpfile = sys_get_temp_dir() . '/' . $zip_name;
            if ($filesystem->fileExists("$path$zip_name")) {
                //Abrimos el existente y le añadimos los archivos
                try {
                    $contents = $filesystem->read($path . $zip_name);
                    file_put_contents($tmpfile, $contents);
                } catch (FilesystemException|UnableToReadFile $exception) {
                    throw new Exception("No se pudo abrir el archivo zip para el documento $filename.");
                }
                if ($zip->open($tmpfile) !== true) {
                    throw new Exception("Fallo al crear un archivo temporal para {$zip_name}\n");
                }
            } else {
                //Creamos uno nuevo
                if ($zip->open($tmpfile, \ZipArchive::CREATE) !== true) {
                    throw new Exception("Fallo al crear un archivo temporal para {$zip_name}\n");
                }
            }
            if ($zip->addFromString($filename, $xml) === false) {
                throw new Exception("Fallo al escribir documento xml al archivo Zip $zip_name\n");
            }
            if ($zip->close() === false) {
                throw new Exception("Fallo al cerrar el archivo Zip $zip_name\n");
            }
            $contents = file_get_contents($tmpfile);
            if ($contents === false) {
                throw new Exception("Fallo al leer el archivo temporal $zip_name\n");
            }
            unlink($tmpfile);
            try {
                $filesystem->write("$path$zip_name", $contents);
            } catch (FilesystemException|UnableToWriteFile $exception) {
                throw new Exception("Fallo al guardar el archivo $zip_name\n");
            }
        }

        //Verificar el estado del documento que se esta recepcionando
        $error = false;
        if ($xml = $this->cogerXml($datos['Clave'], 'R', 2, $id_empresa)) {
            //Xml de respuesta se encuentra disponible
            $datosXML = Comprobante::analizarXML($xml);
            $estado = $datosXML['Mensaje'] == 1 ? 3 : 4;
            if ($estado != 3) {
                $error = "El documento que se está intentando recepcionar se encuentra rechazado por Hacienda.";
            }
        } else {
            //Consultar el estado en Hacienda
            $o_datos = [
                'clave' => $datos['Clave'],
                'tipo' => 'C',
            ];
            $c_original = new Comprobante($this->container, $o_datos, $id_empresa);
            $c_original->consultarEstado();
            if ($c_original->estado != 3) {
                //No esta aceptado
                if ($c_original->estado == 4) {
                    //Rechazado
                    $error = "El documento que se está intentando recepcionar se encuentra rechazado por Hacienda.";
                } elseif ($c_original->enHacienda === false) {
                    //No se encuentra en Hacienda
                    $error = "El documento que se está intentando recepcionar no se encuentra en Hacienda. Intente más tarde.";
                } else {
                    //Ocurrio un error al consultar estado
                    $error = "Ocurrió un error al consultar el estado del documento que se está intentando recepcionar. Intente más tarde.";
                }
            }
        }

        if ($error) {
            throw new Exception($error);
        }

        //Crear el comprobante de recepcion
        $comprobante = new Comprobante($this->container, $datos, $id_empresa);
        return $comprobante->guardarEnCola();
    }

    /**
     * Consultar el estado de un comprobante
     *
     * @param string $clave      La clave del comprobante a interrogar
     * @param string $tipo       E para Emisiones, R para Recepciones
     * @param int    $id_empresa ID unico de empresa
     *
     * @return array El resultado
     */
    public function consultarEstado($clave, $tipo, $id_empresa)
    {
        $datos = [
            'clave' => $clave,
            'tipo' => $tipo,
        ];
        $comprobante = new Comprobante($this->container, $datos, $id_empresa);
        $estado = $comprobante->estado;

        $xml = '';
        if ($estado > 2 && $estado < 5) {
            // ya tenemos la respuesta de Hacienda en la base de datos
            $xml = $comprobante->cogerXmlRespuesta();
        } elseif ($estado == 2) {
            // comprobante esta enviado
            $comprobante->consultarEstado();
            $estado = $comprobante->estado;
            if ($estado > 2 && $estado < 5) {
                // Solo un comprobante aceptado o rechazado tiene xml de respuesta
                $xml = $comprobante->cogerXmlRespuesta();
            }
        } elseif ($estado == 1) {
            // no se ha enviado
            $comprobante->enviar();
            $estado = $comprobante->estado;
        }
        $estado_texto = ['pendiente', 'pendiente', 'enviado', 'aceptado', 'rechazado', 'error'][$estado];
        if ($xml === false) {
            throw new XmlNotFoundException("Consultando estado para el doc $clave con estado $estado_texto dio un error de XML de respuesta no disponible.");
        }
        return [
            'clave' => $clave,
            'estado' => $estado_texto,
            'mensaje' => $comprobante->cogerDetalleMensaje(),
            'xml' => $xml //xml de respuesta de Hacienda
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
        } elseif ($lugar == 'R') {
            //Recepcion
            $tabla = 'fe_recepciones';
        } else {
            return false;
        }
        $sql = "SELECT estado FROM $tabla WHERE clave=$clave AND id_empresa=$id";
        $res = $db->query($sql);
        if ($res->num_rows > 0 || ($tipo < 3 && $lugar == 'R')) {
            if ($lugar == 'E' || ($tipo > 2 && $lugar == 'R')) {
                $estado = $res->fetch_row()[0];
                if (($tipo == 2 && $estado < 3 && $lugar == 'E')) {
                    //No existe respuesta para emision
                    return false;
                } elseif (($tipo == 4 && $estado < 3 && $lugar == 'R')) {
                    //No existe respuesta para recepcion
                    return false;
                }
            }

            //Conseguir el archivo
            $storage_path = "$id/20" . substr($clave, 7, 2) . substr($clave, 5, 2) . '/';

            $zip_name = $lugar . $clave . '.zip';
            if ($tipo == 2) {
                $tipo_doc = 'MH';
            } elseif ($tipo == 4) {
                $tipo_doc = 'MHMR';
            } else {
                $tipo_doc = substr($clave, 29, 2) - 1;
                $tipo_doc = ['FE', 'NDE', 'NCE', 'TE', 'MR', 'MR', 'MR', 'FEC', 'FEE', 'REP'][$tipo_doc];
            }
            $filename = $tipo_doc . $clave . '.xml';

            $filesystem = $this->container['filesystem'];
            $tmpfile = sys_get_temp_dir() . '/' . $zip_name;
            try {
                $contents = $filesystem->read($storage_path . $zip_name);
                file_put_contents($tmpfile, $contents);
            } catch (FilesystemException|UnableToReadFile $exception) {
                throw new Exception("No se pudo leer el archivo <{$zip_name}>");
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpfile) !== true) {
                throw new Exception("Fallo al abrir <{$zip_name}> abriendo xml\n");
            }

            if ($zip->locateName($filename) !== false) {
                //XML esta guardado
                $xml = $zip->getFromName($filename);
            } else {
                $zip->close();
                unlink($tmpfile);
                return false;
            }
            $zip->close();
            unlink($tmpfile);
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
            $tabla = 'fe_emisiones';
        } elseif ($tipo == 'R') {
            //Recepcion
            $tabla = 'fe_recepciones';
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
     * y devolver una lista de los comprobantes enviados
     *
     * @param int $timeout Maximo tiempo en segundos para gastar enviando comprobantes
     *
     * @return array [[clave => clave, tipo => E o R], [...]] con todos los que se enviaron
     */
    public function enviarCola($timeout = 0)
    {
        $db = $this->container['db'];
        $log = $this->container['log'];
        $timestamp = (new DateTime())->getTimestamp(); //tiempo actual
        $enviados = [];

        // Prioridad de envio para los documentos mas recientes
        $sql = "SELECT * FROM fe_cola
        WHERE tiempo_enviar <= $timestamp AND accion < 3 AND intentos_envio < 12
        ORDER BY intentos_envio ASC, tiempo_enviar ASC";
        $res = $db->query($sql);
        while ($row = $res->fetch_assoc()) {
            // Ver si hemos pasado el tiempo limite
            if ($timeout > 0 && (new DateTime())->getTimestamp() - $timestamp > $timeout) {
                break;
            }

            $clave = $row['clave'];
            $accion = $row['accion'];
            $datos = [
                'clave' => $clave,
                'tipo' => $accion == 1 ? 'E' : 'R'
            ];
            $comprobante = new Comprobante($this->container, $datos, $row['id_empresa']);
            try {
                if ($row['intentos_envio'] > 0 && $comprobante->consultarEstado()) {
                    // Se logro consultar estado, entonces ya está en Hacienda
                    $enviados[] = [
                        'clave' => $clave,
                        'tipo' => $accion == 1 ? 'E' : 'R',
                        'estado' => $comprobante->estado,
                    ];
                    // Eliminarlo de la cola
                    $sql = "DELETE FROM fe_cola WHERE clave='$clave' AND accion='$accion'";
                    $db->query($sql);
                    continue;
                }
                if ($comprobante->enviar()) {
                    $enviados[] = [
                        'clave' => $clave,
                        'tipo' => $accion == 1 ? 'E' : 'R',
                        'estado' => 2,
                    ];
                }
            } catch (Exception $e) {
                // Error al enviarlo
                $log->error("Error al intentar enviar comprobante: $e");
                $comprobante->desactivarEnvios();
            }
        }
        return $enviados;
    }
}
