<?php
/**
 * Proveedor de almacenaje para el facturador
 * 
 * Funciones para interactuar con la base de datos
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

/**
 * Esta clase provee una conexion a la base de datos
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion\Empresas
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class Storage
{
    /**
     * Create a new connection to a MySql database
     * 
     * @param string $server  Database server
     * @param string $user    Database username
     * @param string $passwd  Database password
     * @param string $db_name Database name
     * 
     * @return \mysqli
     */
    public static function mySql($server, $user, $passwd, $db_name)
    {
        //Create a connection to the database
        $db = new \mysqli($server, $user, $passwd);

        //Check the connection
        if ($db->connect_error) {
            throw new \Exception("Error de conexión MySQL: " . $db->connect_error);
        }


        //Check for database existence
        $db_version = 3; // Set to version required by component
        if ($db->select_db($db_name)) {
            //Check for up to date database version
            Storage::_versionCheck($db, $db_version); 
            return $db;
        } else {            
            return Storage::_createDB($db, $db_name, $db_version);
        }
    }

    /**
     * Create a new database according to our needs
     * 
     * @param \mysqli $conn       The database connection
     * @param string  $db_name    The name of the database to create
     * @param int     $db_version Version of the database saved
     * 
     * @return \mysqli The connection used to add the data
     */
    private static function _createDB($conn, $db_name, $db_version)
    {
        $sql = "CREATE DATABASE $db_name CHARACTER SET utf16 COLLATE utf16_general_ci";
        if (!$conn->query($sql)) {
            throw new \Exception("Error creando base de datos: $conn->error");
        }
        $conn->select_db($db_name);
        $statements = [
            'CREATE TABLE `Ambientes` (
                `Id_ambiente` int(1) NOT NULL,
                `Nombre` varchar(25) NOT NULL,
                `Client_id` varchar(55) NOT NULL,
                `URI_IDP` varchar(255) NOT NULL,
                `URI_API` varchar(255) NOT NULL)',
            "INSERT INTO `Ambientes` 
                (`Id_ambiente`, `Nombre`, `Client_id`, `URI_IDP`, `URI_API`) 
                VALUES
                (1, 'Staging/Sandbox', 'api-stag', 
                'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token', 
                'https://api.comprobanteselectronicos.go.cr/recepcion-sandbox/v1/'),
                (2, 'Producción', 'api-prod', 
                'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token', 
                'https://api.comprobanteselectronicos.go.cr/recepcion/v1/')",
            'CREATE TABLE `Emisiones` (
                `Clave` varchar(55) NOT NULL,
                `Cedula` varchar(55) NOT NULL,
                `Estado` int(1) DEFAULT NULL,
                `msg` VARCHAR(255) NULL,
                `xmlFirmado` blob,
                `Respuesta` blob)',
            'CREATE TABLE `Recepciones` (
                `Clave` VARCHAR(55) NOT NULL ,
                `Cedula` VARCHAR(12) NOT NULL ,
                `Estado` INT(2) NOT NULL ,
                `msg` VARCHAR(255) NULL
                `xmlRecibido` BLOB NULL ,
                `xmlConfirmacion` BLOB NULL ,
                `Respuesta` BLOB NULL ,
                PRIMARY KEY (`Clave`),
                INDEX `Cedula` (`Cedula`))',
            'CREATE TABLE `Empresas` (
                `Cedula` varchar(55) NOT NULL,
                `Nombre` varchar(50) DEFAULT NULL,
                `Email` varchar(50) DEFAULT NULL,
                `Certificado_mh` blob,
                `Usuario_mh` varchar(512) DEFAULT NULL,
                `Password_mh` varchar(512) DEFAULT NULL,
                `Pin_mh` varchar(512) DEFAULT NULL,
                `Id_ambiente_mh` int(1) DEFAULT NULL)',
            'CREATE TABLE `Tokens` (
                `Client_id` varchar(55) NOT NULL,
                `access_token` varchar(2048) CHARACTER SET utf16 NOT NULL,
                `expires_in` INT(16) NOT NULL,
                `refresh_token` varchar(2048) CHARACTER SET utf16 NOT NULL,
                `refresh_expires_in` INT(16) NOT NULL)',
            'CREATE TABLE `Version` ( `db_version` INT(3) NOT NULL )',
            "INSERT INTO `Version` (`db_version`) VALUES ($db_version)",
            'ALTER TABLE `Ambientes`
                ADD PRIMARY KEY (`Id_ambiente`)',
            'ALTER TABLE `Emisiones`
                ADD PRIMARY KEY (`Clave`),
                ADD KEY `Cedula_E` (`Cedula`) USING BTREE',
            'ALTER TABLE `Empresas`
                ADD PRIMARY KEY (`Cedula`),
                ADD KEY `Ambiente` (`Id_ambiente_mh`)',
            'ALTER TABLE `Tokens`
                ADD PRIMARY KEY (`Client_id`)',
            'ALTER TABLE `Emisiones`
                ADD CONSTRAINT `Cedula_E` FOREIGN KEY (`Cedula`) REFERENCES `Empresas` (`Cedula`)',
            'ALTER TABLE `Empresas`
                ADD CONSTRAINT `Ambiente` FOREIGN KEY (`Id_ambiente_mh`) REFERENCES `Ambientes` (`Id_ambiente`) ON UPDATE CASCADE'
            ];
        foreach ($statements as $sql) {
            $conn->query($sql);
        }
        return $conn;
    }

    /**
     * Database updater
     * 
     * @param \mysqli $db         Database connection
     * @param int     $db_version Version to update to
     * 
     * @return bool
     */
    private static function _versionCheck($db, $db_version)
    {
        $sql = "SELECT * FROM Version";
        // Get current database version
        $version = $db->query($sql)->fetch_assoc()['db_version'];
        if ($db_version == $version) {
            return true;
        }

        $versions = [
            2 => [
                "UPDATE Version SET `db_version`=$db_version",
                'CREATE TABLE `Recepciones` (
                    `Clave` VARCHAR(55) NOT NULL ,
                    `Cedula` VARCHAR(12) NOT NULL ,
                    `Estado` INT(2) NOT NULL ,
                    `xmlRecibido` BLOB NULL ,
                    `xmlConfirmacion` BLOB NULL ,
                    `Respuesta` BLOB NULL ,
                    PRIMARY KEY (`Clave`),
                    INDEX `Cedula` (`Cedula`))'
            ],
            3=> [
                "UPDATE Version SET `db_version`=$db_version",
                "ALTER TABLE `Emisiones`
                    ADD `msg` VARCHAR(255) NULL AFTER `Estado`;",
                "ALTER TABLE `Recepciones`
                    ADD `msg` VARCHAR(255) NULL AFTER `Estado`;"
            ]
        ];
        foreach ($versions as $ver => $statements) {
            if ($ver > $version) {
                foreach ($statements as $statement) {
                    $db->query($statement);
                }                
                echo $db->error;
            }
        }
        return true;
    }

}