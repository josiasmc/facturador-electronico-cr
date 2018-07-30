<?php

namespace Contica\eInvoicing;

use \Defuse\Crypto\Crypto;
use  \PHPUnit\Framework\TestCase;

class CompaniesTest extends TestCase
{

    public function testCompanyExists()
    {
        $container = include 'container.php';
        $companies = new Companies($container);
        $this->assertTrue($companies->exists(603960916));
    }

    public function testCompanyDoesNotExist()
    {
        $container = include 'container.php';
        $companies = new Companies($container);
        $this->assertFalse($companies->exists(20140369));
    }
}