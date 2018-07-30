# facturacion-electronica-cr

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

Este es un componente PHP que provee una solución completa para cumplir con la normativa DGT-R-2016 del Ministerio de Hacienda en Costa Rica.

## Instalación

Por medio de Composer

``` bash
composer require contica/facturacion-electronica-cr
```

## Uso

``` php
/**
* Ajustes minimos para el facturador
*/
$opciones = [
    'servidor' => 'localhost',
    'usuario' => 'root',
    'contraseña' => 'contra'
];
$facturador = new Contica\eFacturacion\Facturador($opciones);

// Guardar una empresa
$facturador->guardarEmpresa($id, $datos);

// Coger los datos de una empresa
$facturador->cogerEmpresa($id);

// Leer el certificado de una empresa
$facturador->cogerCertificadoEmpresa($id);


```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Pruebas

``` bash
composer test
```

## Seguridad

Si descubre problemas relacionados con seguridad, favor enviar un correo a josiasmc@emypeople.net.

## Créditos

- [Josias Martin][link-author]
- [Todos los contribuyentes][link-contributors]

## Licencia

La licencia MIT (MIT). Favor ver [Licencia](LICENSE.md) para más información.

[ico-version]: https://img.shields.io/packagist/v/contica/cr-electronic-invoicing.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/contica/cr-electronic-invoicing/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/contica/cr-electronic-invoicing.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/contica/cr-electronic-invoicing.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/contica/cr-electronic-invoicing.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/contica/cr-electronic-invoicing
[link-travis]: https://travis-ci.org/contica/cr-electronic-invoicing
[link-scrutinizer]: https://scrutinizer-ci.com/g/contica/cr-electronic-invoicing/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/contica/cr-electronic-invoicing
[link-downloads]: https://packagist.org/packages/contica/cr-electronic-invoicing
[link-author]: https://github.com/josiasmc
[link-contributors]: ../../contributors
