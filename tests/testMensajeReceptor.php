<?php
use Contica\eFacturacion\Facturador;
use Contica\eFacturacion\Token;
use \GuzzleHttp\Client;

require __DIR__ . '/../vendor/autoload.php';
$container = include __DIR__ . '/container.php';

$clave = '50613081800060396091600100001010000000012168688858';
$id = '603960916';
$facturador = new Facturador(['contra' => 'josi14.cr']);
$token = new Token($id, $container);
$token = $token->getToken();
$client = new Client(
    ['headers' => ['Authorization' => 'bearer ' . $token]]
);
$sql  = 'SELECT Ambientes.URI_API '.
            'FROM Ambientes '.
            'LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente '.
            'WHERE Empresas.Cedula=' . $id;
$url = $db->query($sql)->fetch_assoc()['URI_API'] . "recepcion/$clave";

$res = $client->request('GET', $url);
echo $res->getStatusCode();
// "200"
echo $res->getHeader('content-type');
// 'application/json; charset=utf8'
$body = $res->getBody();
$ans = $facturador->procesarMensajeHacienda($body);
echo 'Clave: ' . $clave . "\n";
echo 'Estado: ';
if (is_string($ans)) {
    echo $ans;
} else {
    echo $ans['Estado'];
}
echo "\n";