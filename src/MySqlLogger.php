<?php

namespace Contica\Facturacion;

use Exception;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Handler\AbstractProcessingHandler;

class MySqlLogger extends AbstractProcessingHandler
{
    public function __construct(
        private \mysqli $db,
        $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO fe_monolog (channel, level, message, time) VALUES (?, ?, ?, ?)",
        );
        if ($stmt === false) {
            throw new Exception(
                "Failed to prepare statement for Monolog Writer",
            );
        }
        $channel = $record->channel;
        $level = $record->level->value;
        $message = $record->formatted;
        $time = $record->datetime->format("U");
        $stmt->bind_param("siss", $channel, $level, $message, $time);
        $stmt->execute();
        $stmt->close();
    }
}
