# facturador-electronico-cr

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

Este es un componente PHP que provee toda la funcionalidad para crear, enviar, y
almacenar los comprobantes electrónicos
requeridos por el Ministerio de Hacienda en Costa Rica.

## Instalación

Por medio de Composer

``` bash
composer require contica/facturador-electronico-cr
```

## Inicializar

``` php
/**
 * Crear la conexión a la base de datos
 */
$db = new mysqli(
    'host',
    'usuario',
    'contraseña',
    'base_de_datos'
);

/**
 * Inicializar la base de datos
 * Tambien para ejecutar migraciones después de una actualización
 */
Storage::runMigrations($db);

```

## Inicializar componente

Si quiere encriptar los datos de conexión a Hacienda en la base de datos
se puede crear una llave de encriptación usando el siguiente comando:

```bash
composer generateKey
```

o usando el método

```php
FacturadorElectronico::crearLlaveSeguridad();
```

El valor que genera se debe guardar en un lugar seguro y suministrarlo al
ajuste `crypto_key` en la lista de ajustes.

``` php
$ajustes = [
    'storage_path' => '', // ruta completa en donde guardar los comprobantes
    'crypto_key' => '',   // (opcional) Llave para encriptar datos de conexion
    'callback_url' => '',  // (opcional) URL donde se recibe el callback
    'storage_type' => 'local', // 'local' o 's3' para el tipo de almacenaje
    's3_client_options' => [ // ajustes opcionales si se usa almacenaje s3
        'credentials' => [
            'key'    => 'llave',
            'secret' => 'secreto'
        ],
        'endpoint' => 'https://us-east-1.linodeobjects.com', // (opcional)
        'region' => 'region', // por ej, us-east-1
        'version' => 'latest', // version
    ],
    's3_bucket_name' => 'nombre_de_bucket' // (opcional) nombre de balde en s3
];

/**
 * Esto crea el objeto que se usa para ejecturar todos los métodos disponibles
 */
$facturador = new FacturadorElectronico($db, $ajustes);

```

## Registrar empresa emisora en el componente

Para poder procesar los comprobantes de un emisor, primero
hay que registrar el emisor. El siguiente método se usa para
registrar un emisor nuevo al igual que actualizarlo.

``` php
$llave = file_get_contents('llave_criptografica.p12');
$datos_de_empresa = [
    'cedula'   => '909990999',
    'ambiente' => '1',         // 1 prod, 2 stag
    'usuario'  => 'usuario_mh',
    'contra'   => 'contraseña_mh',
    'pin'      => 'pin_llave',
    'llave_criptografica' => $llave
];
/**
 * Registrar empresa nueva
 *
 * El valor devuelto se tiene que usar para todas las
 * operaciones futuras sobre la empresa registrada
 */
$id_empresa = $facturador->guardarEmpresa($datos_de_empresa);

/**
 * Modificar los datos de la empresa
 *
 * En $datos_de_empresa solo es necesario incluir los datos
 * que se están modificando
 */
$facturador->guardarEmpresa($datos_de_empresa, $id_empresa);

```

## Crear un comprobante electrónico

```php
/**
 * Datos de ejemplo para factura electrónica
 *
 * La estructura del array debe cumplir con la estructura
 * establecida para los comprobantes electrónicos
 */
$comprobante = [
    'Clave' => '{$clave}', // Omitir nodo para que se genere automáticamente
    'NumeroConsecutivo' => '00100001010000000001',
    'FechaEmision' => date('c'),
    'Emisor' => [
        'Nombre' => 'Emisor',
        'Identificacion' => [
            'Tipo' => '01',
            'Numero' => '909990999'
        ],
        'Ubicacion' => [
            'Provincia' => '6',
            'Canton' => '01',
            'Distrito' => '01',
            'OtrasSenas' => 'direccion'
        ],
        'CorreoElectronico' => 'correo@gmail.com'
    ],
    'Receptor' => [
        'Nombre' => 'Receptor',
        'Identificacion' => [
            'Tipo' => '01',
            'Numero' => '909990999'
        ],
        'Ubicacion' => [
            'Provincia' => '6',
            'Canton' => '01',
            'Distrito' => '01',
            'OtrasSenas' => 'direccion'
        ],
        'CorreoElectronico' => 'correo@gmail.com'
    ],
    'CondicionVenta' => '01',
    'MedioPago' => ['01', '02'],
    'DetalleServicio' => [
        'LineaDetalle' => [
            [
                'NumeroLinea' => '1',
                'Codigo' => 'codigo CABYS',
                'CodigoComercial' => [
                    'Tipo' => '01',
                    'Codigo' => '00001'
                ],
                'Cantidad' => '1',
                'UnidadMedida' => 'Unid',
                'Detalle'  => 'Producto sin IVA',
                'PrecioUnitario' => '15000.00',
                'MontoTotal' => '15000.00',
                'Descuento' => [ // Incluir cuando hay descuento
                    'MontoDescuento' => '1000.00',
                    'NaturalezaDescuento' => '...'
                ],
                'Subtotal' => '14000.00',
                'MontoTotalLinea' => '14000.00'
            ],
            [
                'NumeroLinea' => '2',
                'Codigo' => 'codigo CABYS',
                'Codigo' => [
                    'Tipo' => '04',
                    'Codigo' => '00002'
                ],
                'Cantidad' => '2',
                'UnidadMedida' => 'Hr',
                'Detalle'  => 'Servicio con IVA',
                'PrecioUnitario' => '3000.00',
                'MontoTotal' => '6000.00',
                'Subtotal' => '6000.00',
                'Impuesto' => [
                    'Codigo' => '01',
                    'CodigoTarifa' => '08',
                    'Tarifa' => '13.00',
                    'Monto' => '780.00'
                ]
                'MontoTotalLinea' => '6780.00'
            ]
        ]
    ],
    'ResumenFactura' => [
        'TotalServGravados' => '6000.00',
        'TotalMercanciasExentas' => '15000.00',
        'TotalGravado' => '6000.00',
        'TotalExento' => '15000.00',
        'TotalVenta' => '21000.00',
        'TotalDescuentos' => '1000.00',
        'TotalVentaNeta' => '20000.00',
        'TotalImpuesto' => '780.00',
        'TotalComprobante' => '20780.00'
    ]
];

/**
 * Esta funcion devuelve la clave del comprobante
 * Necesario para futuras consultas
 */
$clave = $facturador->enviarComprobante(
    $comprobante,
    $id_empresa
);

```

El comprobante generado queda guardado en la cola de envío.
Para enviarlo a Hacienda, se ejecuta el siguiente método:

```php
$docs_enviados = $facturador->enviarCola();

/**
 * Contenido de ejemplo devuelto en $docs_enviados
 *
 * [
 *     [
 *         'clave' => 'clave...',
 *         'tipo' => 'E', // E para emision, R para recepcion
 *     ],
 *     [...]
 * ]
 *
 */
```

## Consultar el estado

```php
$estado = $facturador->consultarEstado(
    $clave,
    'E', // E para emision, R para recepcion
    $id_empresa
);

/**
 * Contenido de ejemplo devuelto en $estado
 *
 * [
 *     'clave' => 'clave...',
 *     'estado' => 1, // 1 pendiente, 2 enviado, 3 aceptado, 4 rechazado
 *     'mensaje' => 'Mensaje Hacienda',
 *     'xml' => 'Contenido del xml de respuesta si existe'
 * ]
 *
 */
```

## Coger el xml de un comprobante

Hay que especificar cuál tipo es el que uno quiere.

- 1: XML del comprobante
- 2: XML de respuesta para el tipo 1
- 3: XML del mensaje receptor para un comprobante recibido (recepciones)
- 4: XML de la respuesta para el tipo 3 (recepciones)

```php
$xml = $facturador->cogerXml(
    $clave,
    'E', // E para emision, R para recepcion
    1,   // El tipo
    $id_empresa
);

/**
 * Convertir el xml a un array para poder procesar
 */
$xml_decodificado = Comprobante::analizarXML($xml);

```

## Procesar callback de Hacienda

Código para pasar el contenido del POST que hace Hacienda al facturador.
Implementar el callback hace innecesario estar siempre consultando manualmente
el estado.

```php
$body = file_get_contents('php://input');

$estado = $facturador->procesarCallbackHacienda($body);

/**
 * El contenido de $estado es idéntico a Consultar Estado más arriba.
 */
```

## Registro de cambios

Por favor vea el [CHANGELOG](CHANGELOG.md) para más información
de lo que ha cambiado recientemente.

## Pruebas

``` bash
composer test
```

## Seguridad

Si descubre problemas relacionados a la seguridad, por favor
envíe un correo electrónico a josias@solucionesinduso.com.

## Créditos

- [Josias Martin][link-author]
- [Todos los contribuyentes][link-contributors]

## Licencia

Licencia MIT (MIT). Favor ver [LICENCIA](LICENSE.md) para más información.

[ico-version]: https://img.shields.io/packagist/v/contica/facturador-electronico-cr.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/contica/facturador-electronico-cr/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/contica/facturador-electronico-cr.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/contica/facturador-electronico-cr.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/contica/facturador-electronico-cr.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/contica/facturador-electronico-cr
[link-travis]: https://travis-ci.org/contica/facturador-electronico-cr
[link-scrutinizer]: https://scrutinizer-ci.com/g/contica/facturador-electronico-cr/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/contica/facturador-electronico-cr
[link-downloads]: https://packagist.org/packages/contica/facturador-electronico-cr
[link-author]: https://github.com/josiasmc
[link-contributors]: ../../contributors
