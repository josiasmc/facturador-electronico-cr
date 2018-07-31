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
            'cryptoKey' => 
                'def0000057b1b0528f59f7ba3da8a25f60e9498bb0060'.
                'a652843681d9f8ca53746679318aab2e54a9d4c2485f4'.
                '6441709de9f0c4aa494dc31acf3d64484f88089296ebe6'
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
            'cryptoKey' => Key::loadFromAsciiSafeString($config['cryptoKey']),
            'db' => $db
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
            return $empresas->update($id, $datos);
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
}
