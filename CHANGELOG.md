# Changelog

Cambios notables en `facturador-electronico-cr` serán documentados aquí.

Actualizaciones deben seguir los principios en [Mantenga un CHANGELOG](https://keepachangelog.com/es-ES/1.0.0/).

## [Unreleased]

## [3.6.3] - 2025-06-23

### Fixed

- Tirar excepción cuando hay un fallo en preparar consultas de SQL.

## [3.6.2] - 2025-04-05

### Fixed

- No tirar error de falta de xml cuando Hacienda responde con un mensaje de error.

## [3.6.1] - 2025-04-05

### Fixed

- Manejar correctamente el estado de error que devuelve Hacienda al consultar un estado de comprobante.

## [3.6.0] - 2025-02-21

### Fixed

- Suprimir error de deprecamiento de PHP74 en SDK de AWS
- Actualización de PDF para la resolución de facturación, junto con su hash. Gracias, [fdelapena](https://github.com/fdelapena).

## [3.5.3] - 2024-12-23

### Fixed

- Arreglo de regresión en lugar donde se mete la clave autogenerada si los datos de factura no traen clave.

## [3.5.2] - 2024-12-21

### Fixed

- Algunas declaraciones de funciones fueron arregladas

## [3.5.1] - 2024-12-21

### Fixed

- Algunas dependencias fueron devueltas a una versión que permite su uso en php 7.4

## [3.5.0] - 2024-12-21

### Added

- Más validaciones agregadas al manejo de archivos temporales.

### Fixed

- Algunas más validaciones para manejar errores en algunos casos adicionales.
- Limpieza de código en general.
- Actualización de dependencias.

### Removed

- La función `Storage::run_migrations()` para actualizar la base de datos
fue eliminada. Use `Storage::runMigrations()` en su lugar.

## [3.4.1] - 2024-05-13

- Arreglar error donde aplazar envíos de un documento que había llegado al límite
de intentos de reenvío no se le desactivaban los envíos.
- Aplazar envío si no se halla la información del xml a la hora de enviar

## [3.4.0] - 2024-05-13

- Optimizar envío de cola de documentos pendientes, dando prioridad a documentos que se envían por primera vez
- Limitar intentos de envíos por sesión cuando hay errores de consulta relacionado a una cédula
- Introducir límite de tiempo de ejecución en el proceso de enviar documentos pendientes

## [3.3.7] - 2023-01-24

- Actualizar dependencias

## [3.3.6] - 2023-01-24

- Permitir instalaciones en PHP 8

## [3.3.5] - 2022-10-10

- No dar error al intentar guardar una emision mas de una vez

## [3.3.4] - 2022-07-21

### Fixed

- Arreglar falta de aplazar envios cuando no se puede conseguir un token para el API

## [3.3.3] - 2022-06-15

### Fixed

- Actualización a dependencias
- Permitir varios documentos de referencia
- Actualizar URL de Hacienda para el ambiente de pruebas

## [3.3.2] - 2021-09-29

### Fixed

- Actualización a dependencias

## [3.3.1] - 2020-11-23

### Fixed

- Arreglo al almacenar recepciones cuando no existe el archivo zip

## [3.3.0] - 2020-11-21

### Added

- Opción para guardar los comprobantes en almacenaje compatible con S3

## [3.2.1] - 2020-08-09

### Fixed

- Leer información en `OtrosCargos` al analizar xmls

## [3.2.0] - 2020-05-23

### Added

- Cuando ocurre un error fatal al comunicarse con Hacienda, quitar el
comprobante de la cola de envío
- Desactivar reintento de envios al haber fallos por 3 días
- El firmador de xmls tira una excepción si la llave criptográfica está vencida.
- La función `Storage::runMigrations()` para reemplazar `Storage::run_migrations()`

### Changed

- Las columnas de `clave` en la base de datos fueron cambiados a DECIMAL
para ahorrar espacio
- Optimizaciones varias en el firmador de xmls
- Limpieza general de código
- Actualzación de las dependencias

### Removed

- Soporte para crear xmls de la versión 4.2 fue eliminado

### Deprecated

- La función `Storage::run_migrations()` para actualizar la base de datos
va a ser eliminada en una versión futura. Use `Storage::runMigrations()`.

## [3.1.1] - 2020-01-07

### Fixed

- No terminar en error cuando la respuesta de Hacienda viene sin el xml (sucede)

## [3.1.0] - 2019-11-02

### Added

- Comprobar que un comprobante esté aceptado en Hacienda antes de intentar recepcionarlo

### Changed

- Limites de consultas al API de Hacienda
únicamente se aplican en el ambiente de Staging
