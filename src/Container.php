<?php

/**
 * Clase para sostener la configuración del sistema
 *
 * @package   Contica\FacturadorElectronico
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2026 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

use Monolog\Logger;
use League\Flysystem\Filesystem;

class Container
{
    public function __construct(
        public \mysqli $db,
        public string $crypto_key = "",
        public int $client_id = 0,
        public string $storage_path = "",
        public string $callback_url = "",
        public string $storage_type = "local",
        public string $s3_bucket_name = "",
        public array $s3_storage_options = [],
        public Logger $logger,
        public RateLimiter $rate_limiter,
        public Filesystem $filesystem,
    ) {}

    public static function fromArray(array $data): self
    {
        $filtered_data = array_intersect_key(
            $data,
            array_flip(self::allowed_keys),
        );

        return new self(...$filtered_data);
    }

    private const allowed_keys = [
        "callback_url",
        "client_id",
        "crypto_key",
        "db",
        "filesystem",
        "logger",
        "rate_limiter",
        "s3_bucket_name",
        "s3_storage_options",
        "storage_path",
        "storage_type",
    ];
}
