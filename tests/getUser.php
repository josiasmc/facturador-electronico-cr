<?php
use Contica\eInvoicing\Invoicer;
require __DIR__ . '/../vendor/autoload.php';
$invoicer = new Invoicer(['password' => 'localhost']);
$company = $invoicer->getCompany(901230456);
var_dump($company);
$cert = $invoicer->getCompanyCertificate(901230456);

$file = fopen(__DIR__ . "/fromDB.p12", "w");
fwrite($file, $cert);
fclose($file);