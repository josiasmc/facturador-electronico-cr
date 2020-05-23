<?php

namespace Contica\Facturacion;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class MySqlLogger extends AbstractProcessingHandler
{
    private $initialized = false;
    private $db;
    private $statement;

    public function __construct(\mysqli $db, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $channel = $record['channel'];
        $level = $record['level'];
        $message = $record['formatted'];
        $time = $record['datetime']->format('U');
        $this->statement->bind_param('siss', $channel, $level, $message, $time);

        $this->statement->execute();
    }

    private function initialize()
    {
        $this->statement = $this->db->prepare(
            'INSERT INTO fe_monolog (channel, level, message, time) VALUES (?, ?, ?, ?)'
        );

        $this->initialized = true;
    }
}
