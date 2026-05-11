<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $dbPath = $config->get('DB_PATH', dirname(__DIR__) . '/var/vpnweb.sqlite');
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
