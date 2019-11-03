<?php
/**
 * Estadisticas de uso del API de Hacienda
 * Nota: Los limites de acceso unicamente se aplican a Sandbox
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
 * Clase para manejar los limites de uso del API de Hacienda
 * 
 * @category Facturacion-electronica
 * @package  Contica\Facturacion\Estadisticas
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class RateLimiter
{
    protected $db; //Conexion a la db
    protected $rate_cache = []; //Cache con registros de uso de contribuyentes
    protected $id_cache = []; //Cache con cedulas de contribuyentes
    protected $limits; //Limites para las reglas disponibles
    protected $log; //Logger del componente

    const REQUESTS = 0; //Consultas o envios totales
    const POST_202 = 1; //Envios con resultado 202
    const POST_401_403 = 2; //Envios con token expirado o mal formado
    const POST_40X = 4; //Reenvios o estructura mal formada
    const GET_200 = 8; //Consultas con estado 200
    const GET_40X = 16; //Consultas con tokens o clases invalidas
    const IDP_200 = 32; //Solicitud de token
    const IDP_401_403 = 64; //Solicitud con token o claves invalidas
    const IDP_REQUEST = 128; //Uso interno para restringir consultas al IDP


    /**
     * Constructor del controlador de API
     * 
     * @param array $container   El contenedor del facturador
     */
    public function __construct($container)
    {
        $this->db = $container['db'];
        $this->log = $container['log'];

        //Establecer los limites por minuto definidos por Hacienda
        $this->limits = [
            RateLimiter::REQUESTS => 300,
            RateLimiter::POST_202 => 100,
            RateLimiter::POST_401_403 => 5,
            RateLimiter::POST_40X => 10,
            RateLimiter::GET_200 => 100,
            RateLimiter::GET_40X => 20,
            RateLimiter::IDP_200 => 10,
            RateLimiter::IDP_401_403 => 5,
            RateLimiter::IDP_REQUEST => 10
        ];
    }

    /**
     * Coger la cedula usando ID de empresa
     * 
     * @param int ID de empresa
     * 
     * @return string Cedula de la empresa
     */
    private function getCedula($id)
    {
        //Coger la cedula de la empresa
        if (isset($this->id_cache[$id])) {
            $cedula = $this->id_cache[$id];
        } else {
            $sql = "SELECT cedula, id_ambiente FROM fe_empresas WHERE id_empresa=?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_row();
            $aplicar_limite = $r[1] == 1; //Limites unicamente en staging
            $cedula = $aplicar_limite ? $r[0] : 0;
            $this->id_cache[$id] = $cedula;
        }
        return $cedula;
    }

    /**
     * Conseguir los limites actuales del contribuyente
     * 
     * @param string $cedula Cedula del contribuyente
     * 
     * @return array Los limites restantes para cada regla
     */
    private function getUserLimits($cedula)
    {
        $limits = $this->limits;
        $timestamp = (new \DateTime())->getTimestamp(); //tiempo actual
        $rate_cache = $this->rate_cache;
        $refresh = false;
        if (isset($rate_cache[$cedula])) {
            //Consultar si los datos son frescos
            if($rate_cache[$cedula]['last_query'] < $timestamp - 15) {
                //Datos con mas de 15 segundos, refrescar
                $refresh = true;
            }
        } else {
            $refresh = true;
        }
        if ($refresh) {
            //Cargar valores predeterminados
            $rate_cache[$cedula] = [
                'last_query' => $timestamp,
                'limits' => $limits
            ];

            //Consultar transacciones del ultimo minuto
            $lastMinute = $timestamp - 60;
            $sql = "SELECT regla, COUNT(*) FROM fe_ratelimiting
            WHERE cedula='$cedula' AND tiempo >= '$lastMinute'
            GROUP BY regla";
            $res = $this->db->query($sql);
            if ($res->num_rows > 0) {
                //Revisar cuanto se ha enviado
                while ($r = $res->fetch_row()) {
                    $rule = $r[0];
                    $cant = $r[1];
                    $rate_cache[$cedula]['limits'][$rule] -= $cant;
                    $rate_cache[$cedula]['limits'][RateLimiter::REQUESTS] -= $cant;
                }
            }
        }
        //Actualizar cache
        $this->rate_cache = $rate_cache;
        return $rate_cache[$cedula]['limits'];
    }

    /**
     * Ver si el contribuyente puede hacer un envio
     * 
     * @param int $id El id unico de la empresa
     * 
     * @return bool Si tiene permiso para enviar
     */
    public function canPost($id)
    {
        $cedula = $this->getCedula($id);
        if ($cedula === 0) return true; //Contribuyente sin limites

        $userLimits = $this->getUserLimits($cedula);

        //Si cualquiera de estos limites llego a 0, devolver false
        $canPost = $userLimits[RateLimiter::REQUESTS] <= 0 ? false : true;
        $canPost = $userLimits[RateLimiter::POST_202] <= 0 ? false : $canPost;
        $canPost = $userLimits[RateLimiter::POST_401_403] <= 0 ? false : $canPost;
        $canPost = $userLimits[RateLimiter::POST_40X] <= 0 ? false : $canPost;
        return $canPost;
    }

    /**
     * Ver si el contribuyente puede hacer una consulta
     * 
     * @param int $id El id unico de la empresa
     * 
     * @return bool Si tiene permiso para consultar
     */
    public function canGet($id)
    {
        $cedula = $this->getCedula($id);
        if ($cedula === 0) return true; //Contribuyente sin limites

        $userLimits = $this->getUserLimits($cedula);

        //Si cualquiera de estos limites llego a 0, devolver false
        $canGet = $userLimits[RateLimiter::REQUESTS] <= 0 ? false : true;
        $canGet = $userLimits[RateLimiter::GET_200] <= 0 ? false : $canGet;
        $canGet = $userLimits[RateLimiter::GET_40X] <= 0 ? false : $canGet;
        return $canGet;
    }

    /**
     * Ver si el contribuyente puede conseguir un token
     * 
     * @param int $id El id unico de la empresa
     * 
     * @return bool Si tiene permiso para coger token
     */
    public function canGetToken($id)
    {
        $cedula = $this->getCedula($id);
        if ($cedula === 0) return true; //Contribuyente sin limites
        
        $userLimits = $this->getUserLimits($cedula);

        //Si cualquiera de estos limites llego a 0, devolver false
        $canGetToken = $userLimits[RateLimiter::IDP_200] <= 0 ? false : true;
        $canGetToken = $userLimits[RateLimiter::IDP_401_403] <= 0 ? false : $canGetToken;
        $canGetToken = $userLimits[RateLimiter::IDP_REQUEST] <= 0 ? false : $canGetToken;
        return $canGetToken;
    }

    /**
     * Registrar una transaccion con el API de Hacienda
     * 
     * @param $id   ID de la empresa
     * @param $type Tipo de transaccion
     * 
     * @return bool
     */
    public function registerTransaction($id, $type)
    {
        if (!isset($this->limits[$type]) && $type != RateLimiter::REQUESTS) {
            throw new \Exception('El tipo de transaccion no es valido');
        }

        $cedula = $this->getCedula($id);
        if ($cedula === 0) return true; //Contribuyente sin limites no necesita registros
        
        $timestamp = (new \DateTime())->getTimestamp(); //tiempo actual

        //Registrar transaccion en la cache
        $this->rate_cache[$cedula]['limits'][$type]--;
        $this->rate_cache[$cedula]['limits'][RateLimiter::REQUESTS]--;

        //Registrar transaccion en la base de datos
        $sql = "INSERT INTO fe_ratelimiting (cedula, regla, tiempo)
        VALUES ('$cedula', $type, $timestamp)";
        return $this->db->query($sql);
    }
}