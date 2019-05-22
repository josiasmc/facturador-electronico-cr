<?php
/**
 * Interfaz de almacenaje y lectura de la base de datos
 * 
 * PHP version 7.2
 * 
 * @category  Facturacion-electronica
 * @package   Contica\FacturadorElectronico
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

/**
 * Esta clase provee metodos relacionados a almacenaje en la base de datos
 * 
 * @category Facturacion-electronica
 * @package  Contica\Facturacion\Storage
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class Storage
{

    /**
     * Automatic migrations to the database
     * 
     * @param \mysqli $db The database connection
     * 
     * @return int Current database version
     */
    public static function run_migrations($db)
    {
        $current_version = Storage::_versionCheck($db);
        $versions = [
            1 => [
                //Crear y agregar datos a la tabla de ajustes
                "CREATE TABLE `fe_settings` (
                    `setting` varchar(32) NOT NULL,
                    `value` varchar(255) NOT NULL
                  )",

                "ALTER TABLE `fe_settings`
                    ADD PRIMARY KEY (`setting`)",

                "INSERT INTO `fe_settings` (`setting`, `value`)
                    VALUES ('db_version', '0')",

                //Crear y agregar datos a la tabla de ambientes
                'CREATE TABLE `fe_ambientes` (
                    `id_ambiente` int(1) NOT NULL,
                    `nombre` varchar(25) NOT NULL,
                    `client_id` varchar(55) NOT NULL,
                    `uri_idp` varchar(255) NOT NULL,
                    `uri_api` varchar(255) NOT NULL
                  )',

                "INSERT INTO `fe_ambientes` 
                    (`id_ambiente`, `nombre`, `client_id`, `uri_idp`, `uri_api`) 
                    VALUES
                    (1, 'Staging/Sandbox', 'api-stag', 
                    'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token', 
                    'https://api.comprobanteselectronicos.go.cr/recepcion-sandbox/v1/'),
                    (2, 'Producción', 'api-prod', 
                    'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token', 
                    'https://api.comprobanteselectronicos.go.cr/recepcion/v1/'
                  )",

                // Crear tabla de las empresas
                "CREATE TABLE `fe_empresas` (
                    `id_empresa` int(11) NOT NULL,
                    `id_cliente` int(11) NOT NULL,
                    `id_ambiente` int(1) NOT NULL DEFAULT '1',
                    `cedula` varchar(12) NOT NULL,
                    `usuario_mh` varchar(512) NOT NULL,
                    `contra_mh` varchar(512) NOT NULL,
                    `pin_llave` varchar(512) NOT NULL,
                    `llave_criptografica` blob NOT NULL
                  )",

                // Crear tabla para guardar logs
                "CREATE TABLE `fe_monolog` (
                    `channel` varchar(255) NOT NULL,
                    `level` int(3) NOT NULL,
                    `message` text NOT NULL,
                    `time` int(11) NOT NULL
                  )",

                //Crear indices de las tablas
                'ALTER TABLE `fe_ambientes`
                    ADD PRIMARY KEY (`id_ambiente`)',

                'ALTER TABLE `fe_empresas`
                    ADD PRIMARY KEY (`id_empresa`),
                    ADD KEY `cliente` (`id_cliente`),
                    ADD KEY `id_ambiente` (`id_ambiente`)',
            ]
        ];
        foreach ($versions as $version => $statements) {
            if ($version > $current_version) {
                foreach ($statements as $sql) {
                    $db->query($sql);
                }
                $current_version = $version;
            }
        }
        //Save the current database version
        $db->query("UPDATE fe_settings SET value='$current_version'
        WHERE setting='db_version'");
        return $current_version;
    }

    /**
     * Database version check
     * 
     * @param \mysqli $db         Database connection
     * 
     * @return bool
     */
    private static function _versionCheck($db)
    {
        //Revisar si existe la base de datos
        $sql = "SELECT count(*) FROM information_schema.TABLES
        WHERE TABLE_NAME = 'fe_settings' AND TABLE_SCHEMA in (SELECT DATABASE())";
        $settings_exists = $db->query($sql)->fetch_row()[0];

        if ($settings_exists) {
            // Get current database version
            $sql = "SELECT value FROM fe_settings
                WHERE setting='db_version'";
            return $db->query($sql)->fetch_row()[0];
        } else {
            // No settings table yet
            return 0;
        }
    }
}