<?php

/**
 * Tokens del Ministerio de Hacienda
 *
 * Este controlador se encarga de conseguir y mantener
 * validos los tokens con el Ministerio de Hacienda
 *
 * PHP version 7.4
 *
 * @category  Facturacion-electronica
 * @package   Contica\Facturacion
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git.id>
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

use GuzzleHttp\Client;
use Defuse\Crypto\Crypto;

/**
 * Clase que contiene funciones para manejar tokens
 *
 * @category Facturacion-electronica
 * @package  Contica\Facturacion
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class Token
{
    protected $container; //Contenedor de variables del componente
    protected $id;        //el id de la empresa
    protected $cedula;    //cedula de la empresa
    protected $ambiente;  //ambiente con el cual funciona la empresa
    protected $db;        //la conexion a la base de datos
    protected $cryptoKey; //la llave para descifrar datos

    private const HTTP_TIMEOUT = 45; //timeout para usar en conexiones al IDP

    /**
     * Class constructor
     *
     * @param array  $container Container con las dependencias
     * @param string $id        Id de la empresa
     */
    public function __construct($container, $id)
    {
        date_default_timezone_set('America/Costa_Rica');
        $this->id = $id;
        $this->db = $container['db'];
        $this->cryptoKey = $container['crypto_key'];
        $this->container = $container;

        //coger la cedula de la empresa
        $sql = "SELECT cedula, id_ambiente FROM fe_empresas WHERE id_empresa=?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_row();
        $this->cedula = $r[0];
        $this->ambiente = $r[1];
    }

    /**
     * Funcion para retornar un token
     *
     * @return string Access token, o false si hay un fallo
     */
    public function getToken()
    {
        //Revisar si hay una entrada en la base de datos
        $sql = "SELECT * FROM fe_tokens
        WHERE cedula='{$this->cedula}' AND id_ambiente={$this->ambiente}";
        $result = $this->db->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            //Revisar si el token es valido
            if ($this->validToken($row['expires_in'])) {
                //token existente es valido
                return $row['access_token'];
            } else {
                //Revisar si el refresh_token es valido
                if ($this->validToken($row['refresh_expires_in'])) {
                    //refresh token es valido
                    return $this->refreshToken($row['refresh_token']);
                }
            }
        }
        //Conseguir un token nuevo
        return $this->newToken();
    }

    /**
     * Funcion para coger un token nuevo de Hacienda
     *
     * @return string El access_token que recibe o False si falla
     */
    private function newToken()
    {
        //Conseguir permiso para hacer el POST
        $ratelimiter = $this->container['rate_limiter'];
        if (!$ratelimiter->canGetToken($this->id)) {
            //No existe permiso. Devolver.
            return false;
        }
        $ratelimiter->registerTransaction($this->id, RateLimiter::IDP_REQUEST);
        $data = $this->getAccessDetails();
        if ($data) {
            $uri = $data['uri_idp'];
            $params = [
                'grant_type' => 'password',
                'client_id' => $data['client_id'],
                'username' => $data['usuario_mh'],
                'password' => $data['contra_mh']
            ];
            $client = new Client();
            try {
                $response = $client->post(
                    $uri,
                    [
                        'form_params' => $params,
                        'connect_timeout' => Token::HTTP_TIMEOUT
                    ]
                );
                if ($response->getStatusCode() == 200) {
                    $ratelimiter->registerTransaction($this->id, RateLimiter::IDP_200);
                    $this->container['log']->debug("Token nuevo creado para {$this->cedula}");
                    $body = $response->getBody()->__toString();
                    $accessToken = $this->saveToken($body);
                    return $accessToken;
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                //error de cliente
                //Datos incorrectos de autenticacion
                $this->container['log']->info("Fallo 40x consiguiendo token para {$this->cedula}");
                $ratelimiter->registerTransaction($this->id, RateLimiter::IDP_401_403);
                return false;
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                //handles all exceptions
                return false;
            }
        }
        //No se pudo conseguir la informacion de conexion de la empresa
        return false;
    }

    /**
     * Funcion para renovar un token con el refresh_token
     *
     * @param string $refreshToken El refresh_token para enviar
     *
     * @return string|bool El access_token devuelto, false si falla
     */
    private function refreshToken($refreshToken)
    {
        //Conseguir permiso para hacer el POST
        $ratelimiter = $this->container['rate_limiter'];
        if (!$ratelimiter->canGetToken($this->id)) {
            //No existe permiso. Devolver.
            return false;
        }
        $ratelimiter->registerTransaction($this->id, RateLimiter::IDP_REQUEST);
        $data = $this->getAccessDetails();
        if ($data) {
            $uri = $data['uri_idp'];
            $params = [
                'grant_type' => 'refresh_token',
                'client_id' => $data['client_id'],
                'refresh_token' => $refreshToken
            ];
            $client = new Client();
            try {
                $response = $client->post(
                    $uri,
                    [
                        'form_params' => $params,
                        'connect_timeout' => Token::HTTP_TIMEOUT
                    ]
                );
                if ($response->getStatusCode() == 200) {
                    $ratelimiter->registerTransaction($this->cedula, RateLimiter::IDP_200);
                    $this->container['log']->debug("Token refrescado para {$this->cedula}");
                    $body = $response->getBody()->__toString();
                    $accessToken = $this->saveToken($body);
                    return $accessToken;
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                //a 400 error
                $this->container['log']->info("Fallo 40x refrescando token para {$this->cedula}");
                $ratelimiter->registerTransaction($this->cedula, RateLimiter::IDP_401_403);
                return $this->newToken();
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                // a connection problem
                return false;
            }
        }
        return false;
    }

    /**
     * Funcion que guarda el token recibido de Hacienda
     *
     * @param string $body El cuerpo devuelto de Hacienda
     *
     * @return string El access_token en el cuerpo
     */
    private function saveToken($body)
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return false;
        }
        $now = (new \DateTime())->getTimestamp();
        //Revisar si ya hay un token guardado
        $sql = "SELECT COUNT(*) FROM fe_tokens
        WHERE cedula='{$this->cedula}' AND id_ambiente={$this->ambiente}";
        $hasToken = $this->db->query($sql)->fetch_row()[0];

        $ac = $data['access_token'];
        $ei = $data['expires_in'] + $now;
        $rt = $data['refresh_token'];
        $rei = $data['refresh_expires_in'] + $now;
        if ($hasToken) {
            $sql = "UPDATE fe_tokens SET
                access_token='$ac',
                expires_in='$ei',
                refresh_token='$rt',
                refresh_expires_in='$rei'
                WHERE cedula='{$this->cedula}' AND id_ambiente={$this->ambiente}";
        } else {
            $sql = "INSERT INTO fe_tokens VALUES
                ('{$this->cedula}', '{$this->ambiente}', '$ac', '$ei', '$rt', '$rei')";
        }
        $this->db->query($sql);
        return $ac;
    }

    /**
     * Funcion para recoger los datos necesarios para conectarse con Hacienda
     *
     * @return array|false Array con los datos para hacer las peticiones
     */
    private function getAccessDetails()
    {
        $id = $this->id;
        $sql  = "SELECT e.usuario_mh, e.contra_mh,
        a.client_id, a.uri_idp
        FROM fe_ambientes a
        LEFT JOIN fe_empresas e ON e.id_ambiente = a.id_ambiente 
        WHERE e.id_empresa='$id'";
        $result = $this->db->query($sql);
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            // Decrypt the encrypted entries
            foreach (['usuario_mh', 'contra_mh'] as $key) {
                if ($data[$key]) {
                    $data[$key] = Crypto::decrypt($data[$key], $this->cryptoKey);
                }
            }
            return $data;
        } else {
            return false;
        }
    }

    /**
     * Funcion para revisar la vida de un token
     *
     * @param string $expires La fecha que se tiene que revisar
     *
     * @return bool True si es valido
     */
    private function validToken($expires)
    {
        $now = (new \DateTime())->getTimestamp();
        //Devuelve true si le queda mas que 45 segundos
        //45 segundos para tener tiempo con el API lento de Hacienda
        if (((int) $expires - $now) > 45) {
            return true;
        } else {
            return false;
        }
    }
}
