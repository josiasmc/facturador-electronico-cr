<?php

namespace Contica\Facturacion;

use Exception;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Handler\AbstractProcessingHandler;

class MySqlLogger extends AbstractProcessingHandler
{
    private $db;

    public function __construct(\mysqli $db, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO fe_monolog (channel, level, message, time) VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new Exception('Failed to prepare statement for Monolog Writer');
        }
        $channel = $record['channel'];
        $level = $record['level'];
        $message = $record['formatted'];
        $time = $record['datetime']->format('U');
        $stmt->bind_param('siss', $channel, $level, $message, $time);

        $stmt->execute();
        $stmt->close();
    }
}
