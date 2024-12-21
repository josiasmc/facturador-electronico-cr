<?php

namespace Contica\eFacturacion;

use Defuse\Crypto\Key;
use  PHPUnit\Framework\TestCase;

require 'configs.php';
class InvoicerTest extends TestCase
{
    /**
     * Test that class constructor loads with default settings
     */
    public function testSetsDefaultContainerWithConstructor()
    {
        $invoicer = new Facturador(['password' => $databasePassword]);
        $config = [
            'servidor' => 'localhost',
            'base_datos' => 'e_invoicing',
            'usuario' => 'root',
            'contraseña' => 'localhost',
            'cryptoKey' => implode(
                [
                'def0000057b1b0528f59f7ba3da8a25f60e9498bb0060',
                'a652843681d9f8ca53746679318aab2e54a9d4c2485f4',
                '6441709de9f0c4aa494dc31acf3d64484f88089296ebe6'
                ]
            )
        ];
        $db = new \mysqli(
            $config['servidor'],
            $config['usuario'],
            $config['contraseña'],
            $config['base_datos']
        );
        $container = [
            'cryptoKey' => Key::loadFromAsciiSafeString($config['cryptoKey']),
            'db' => $db
        ];
        $this->assertAttributeEquals($container, 'container', $invoicer);
    }

    /**
     * Test that the function works to add and modify a company
     */

    public function testSetCompany()
    {
        $invoicer = new Facturador(['password' => 'localhost']);
        $cert = fopen(__DIR__ . '/cert.p12', 'r');
        $company = [
            'nombre' => 'PHP_Unit_testing',
            'email' => 'testing@phpunit.org',
            'certificado' => fread($cert, filesize(__DIR__ . '/cert.p12')),
            'usuario' => 'usuarioHacienda',
            'contra' => 'contraHacienda',
            'pin' => 1234,
            'id_ambiente' => 1
        ];
        fclose($cert);
        $this->assertTrue($invoicer->guardarEmpresa(901230456, $company));
    }
}
