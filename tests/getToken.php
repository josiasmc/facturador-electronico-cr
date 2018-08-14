<?php
use Contica\eFacturacion\Token;

require __DIR__ . '/../vendor/autoload.php';
$container = include 'container.php';
$token = new Token(603960916, $container);
try {
    echo $token->getToken();
} catch (\GuzzleHttp\Exception\ClientException $err)  {
    echo $err->getMessage();
} catch (\GuzzleHttp\Exception\ConnectException $err)  {
    echo $err->getMessage();
}

