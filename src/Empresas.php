<?php
/**
 * Companies interface for the eInvoicer
 * 
 * This provides all the functions for handling
 * company creation and modification
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

use \Defuse\Crypto\Crypto;

/**
 * Class providing functions to manage companies
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion\Empresas
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class Empresas
{
    protected $container;


    /**
     * Class constructor
     * 
     * @param array $container The Invoicer container
     */
    public function __construct($container)
    {
        $this->container = $container;
    }

    /**
     * Checks to see if the company exists
     * 
     * @param int $id The cedula of the company
     * 
     * @return bool Existence
     */
    public function exists($id)
    {
        $db = $this->container['db'];
        $sql = 'SELECT Cedula FROM Empresas WHERE Cedula=' . $id;
        return $db->query($sql)->num_rows == 1;
    }

    /**
     * Add a new company to the database
     * 
     * @param int   $id   The cedula of the company to add
     * @param array $data Company information
     * 
     * @return bool Result of the operation
     */
    public function add($id, $data)
    {
        $db = $this->container['db'];
        $prepedData = $this->_prepData($data);
        $prepedData['Cedula'] = $id;
        $fields = "";
        $values = "";
        foreach ($prepedData as $key => $value) {
            $fields .= $key . ', ';
            $values .= $this->_prepValue($value) . ', ';
        }
        $fields = rtrim($fields, ", ");
        $values = rtrim($values, ", ");
        $sql = "INSERT INTO Empresas ($fields) VALUES ($values)";
        if ($db->query($sql) === true) {
            return true;
        } else {
            throw new \Exception("Error adding company: " . $db->error);
        }
        return false;
    }

    /**
     * Modify an existing company
     * 
     * @param int   $id   The company's cedula
     * @param array $data The company's data to modify
     * 
     * @return bool Result
     */
    public function modify($id, $data)
    {
        $db = $this->container['db'];
        $prepedData = $this->_prepData($data);
        $values = '';
        foreach ($prepedData as $key => $value) {
            $values .= $key . '=' . $this->_prepValue($value) . ', ';
        }
        $values = rtrim($values, ', ');
        $sql = "UPDATE Empresas SET $values WHERE Cedula=$id";
        if ($db->query($sql) === true) {
            return true;
        } else {
            throw new \Exception("Error updating company: " . $db->error);
        }
        return false;
    }

    /**
     * Get an existing company
     * 
     * @param int $id The company's cedula
     * 
     * @return array The company's data, without the certificate
     */
    public function get($id)
    {
        $db = $this->container['db'];
        $cryptoKey = $this->container['cryptoKey'];
        $sql = implode(
            [
            "SELECT Cedula As cedula, Nombre AS nombre, Email AS email, ",
            "Usuario_mh AS usuario, ",
            "Password_mh AS contra, Pin_mh AS pin, Id_ambiente_mh AS id_ambiente ",
            "FROM Empresas ",
            "WHERE Cedula=$id"
            ]
        );
        $result = $db->query($sql);
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            // Decrypt the encrypted entries
            foreach (['usuario', 'contra', 'pin'] as $key) {
                if ($data[$key]) {
                    $data[$key] = Crypto::decrypt($data[$key], $cryptoKey);
                }
            }
            return $data;
        }
        return false;
    }

    /**
     * Get the Ministerio de Hacienda certificate
     * 
     * @param int $id The company's cedula
     * 
     * @return string The certificate
     */
    public function getCert($id)
    {
        $db = $this->container['db'];
        $sql = "SELECT Certificado_mh FROM Empresas WHERE Cedula=$id";
        $result = $db->query($sql);
        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['Certificado_mh'];
        }
        return false;
    }

    /**
     * Prepare company data for database storage
     * 
     * @param array $data The data to prepare
     * 
     * @return array The prepared data
     */
    private function _prepData($data)
    {
        $db = $this->container['db'];
        $cryptoKey = $this->container['cryptoKey'];
        // The fields that need sql escaping
        $fields = [
            'Nombre' => 'nombre',
            'Email' => 'email',
            'Certificado_mh' => 'certificado',
            'Id_ambiente_mh' => 'id_ambiente'
        ];
        $prepd = array();
        foreach ($fields as $key => $value) {
            if (array_key_exists($value, $data)) {
                $prepd[$key] = $db->real_escape_string($data[$value]);
            }
        }
        // The fields that need encryption
        $fields = [
            'Usuario_mh' => 'usuario',
            'Password_mh' => 'contra',
            'Pin_mh' => 'pin'
        ];
        foreach ($fields as $key => $value) {
            if (array_key_exists($value, $data)) {
                $prepd[$key] = Crypto::encrypt((string)$data[$value], $cryptoKey);
            }
        }
        return $prepd;
    }

    /** 
     * Quote non integer strings with ''
     * 
     * @param String $value Text needing quotes
     * 
     * @return String
     */
    private function _prepValue($value)
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $value = "'" . $value . "'";
        }
        return $value;
    }
}