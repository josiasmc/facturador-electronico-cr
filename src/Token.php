<?php
/**
 * Tokens del Ministerio de Hacienda
 * 
 * Este controlador se encarga de conseguir y mantener
 * validos los tokens con el Ministerio de Hacienda
 * 
 * PHP version 7.1
 * 
 * @category  Facturacion-electronica
 * @package   Contica\eFacturacion
 * @author    Josias Martin <josiasmc@emypeople.net>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git.id>
 * @link      https://github.com/josiasmc/facturacion-electronica-cr
 */

namespace Contica\eFacturacion;

use \GuzzleHttp\Client;
use \Defuse\Crypto\Crypto;

/**
 * Clase que contiene funciones para manejar tokens 
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class Token
{
    protected $user_id;   //el usuario a la que pertenece el token
    protected $db;        //la conexion a la base de datos
    protected $cryptoKey; //la llave para descifrar datos

    /**
     * Class constructor
     * 
     * @param string $user_id   Id del usuario
     * @param array  $container Container con las dependencias
     */
    public function __construct($user_id, $container)
    {
        $this->user_id = $user_id;
        $this->db = $container['db'];
        $this->cryptoKey = $container['cryptoKey'];
        date_default_timezone_set('America/Costa_Rica');
    }

    /**
     * Funcion para retornar un token
     * 
     * @return String False si no se logra
     */
    public function getToken()
    {
        $id = $this->user_id;
        $db = $this->db;
        //Revisar si hay una entrada en la base de datos
        $sql = "SELECT * FROM Tokens WHERE Client_id=$id";
        $result = $db->query($sql);
        if (is_object($result)) {
            //Revisar si el token es valido
            $row = $result->fetch_assoc();
            if ($this->_validToken($row['expires_in'])) {
                //token valido
                return $row['access_token'];
            } else {
                //Revisar si el refresh_token es valido
                if ($this->_validToken($row['refresh_expires_in'])) {
                    //token valido
                    return $this->_refreshToken($row['refresh_token']);
                }
            }
        }
        //Conseguir un token nuevo
        return $this->_newToken();
    }

    /**
     * Funcion para coger un token nuevo de Hacienda
     * 
     * @return string El access_token que recibe o False si falla
     */
    private function _newToken()
    {
        $data = $this->_getAccessDetails();
        if ($data) {
            $uri = $data['URI_IDP'];
            $params = [
                'grant_type' => 'password',
                'client_id' => $data['Client_id'],
                'username' => $data['Usuario_mh'],
                'password' => $data['Password_mh']
            ];
            $client = new Client();
            try {
                $response = $client->post($uri, ['form_params' => $params]);
                if ($response->getStatusCode()==200) {
                    $body = $response->getBody()->__toString();
                    $accessToken = $this->_saveToken($body);
                    return $accessToken;
                }
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                //handles all exceptions
                return false;
            }
            
        }
        return false;
    }

    /**
     * Funcion para renovar un token con el refresh_token
     * 
     * @param string $refreshToken El refresh_token para enviar
     * 
     * @return string|bool El access_token devuelto, false si falla
     */
    private function _refreshToken($refreshToken)
    {
        $data = $this->_getAccessDetails();
        $id = $this->user_id;
        if ($data) {
            $uri = $data['URI_IDP'];
            $params = [
                'grant_type' => 'refresh_token',
                'client_id' => $data['Client_id'],
                'refresh_token' => $refreshToken
            ];
            $client = new Client();
            try {
                $response = $client->post($uri, ['form_params' => $params]);
                if ($response->getStatusCode()==200) {
                    $body = $response->getBody()->__toString();
                    $accessToken = $this->_saveToken($body);
                    return $accessToken;
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                //a 400 error
                //lets erase the token and try to get a new one
                $db = $this->db;
                $sql = "DELETE FROM Tokens
                        WHERE Client_id=$id";
                return $this->_newToken();
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
    private function _saveToken($body)
    {
        $id = $this->user_id;
        $db = $this->db;
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return false;
        }        
        $now = date_timestamp_get(date_create());
        //Revisar si ya hay un token guardado
        $sql = "SELECT Client_id FROM Tokens WHERE Client_id=$id";
        $ac = $data['access_token'];
        $ei = $data['expires_in'] + $now;
        $rt = $data['refresh_token'];
        $rei = $data['refresh_expires_in'] + $now;
        $result = $db->query($sql);
        if (is_object($result)) {
            $sql = "UPDATE Tokens SET
                access_token='$ac',
                expires_in='$ei',
                refresh_token='$rt',
                refresh_expires_in='$rei'
                WHERE Client_id=$id";
        } else {
            $sql = "INSERT INTO Tokens VALUES
                ('$id', '$ac', '$ei', '$rt', '$rei')";
        }
        $db->query($sql);
        return $ac;
    }

    /**
     * Funcion para recoger los datos necesarios para conectarse con Hacienda
     * 
     * @return array Array con los datos para hacer las peticiones
     */
    private function _getAccessDetails()
    {
        $id = $this->user_id;
        $sql  = "SELECT e.Usuario_mh, e.Password_mh,
        a.Client_id, a.URI_IDP 
        FROM Ambientes a
        LEFT JOIN Empresas e ON e.Id_ambiente_mh = a.Id_ambiente 
        WHERE e.Cedula='$id'";
        $result = $this->db->query($sql);
        if (is_object($result)) {
            $data = $result->fetch_assoc();
            // Decrypt the encrypted entries
            foreach (['Usuario_mh', 'Password_mh'] as $key) {
                if ($data[$key]) {
                    $data[$key] = Crypto::decrypt($data[$key], $this->cryptoKey);
                }
            }
            return $data;
        }
        return false;
    }

    /**
     * Funcion para revisar la vida de un token
     * 
     * @param String $expires La fecha que se tiene que revisar
     * 
     * @return Boolean True si es valido
     */
    private function _validToken($expires)
    {
        $now = date_timestamp_get(date_create());
        //Devuelve true si le queda mas que 5 segundos
        if (((int)$expires-$now) > 5) {
            return true;
        }
        return false;
    }
}
