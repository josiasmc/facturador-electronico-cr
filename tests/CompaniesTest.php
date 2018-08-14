<?php

namespace Contica\eFacturacion;

use \Defuse\Crypto\Crypto;
use  \PHPUnit\Framework\TestCase;

class CompaniesTest extends TestCase
{

    public function testCompanyExists()
    {
        $container = include 'container.php';
        $companies = new Empresas($container);
        $this->assertTrue($companies->exists(603960916));
    }

    public function testCompanyDoesNotExist()
    {
        $container = include 'container.php';
        $companies = new Empresas($container);
        $this->assertFalse($companies->exists(20140369));
    }
}