# Changelog

Cambios notables en `facturador-electronico-cr` serán documentados aquí.

Actualizaciones deben seguir los principios en [Mantenga un CHANGELOG](https://keepachangelog.com/es-ES/1.0.0/).

## [Unreleased]

## [3.3.4] - 2022-10-10

- No dar error al intentar guardar una emision mas de una vez

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
