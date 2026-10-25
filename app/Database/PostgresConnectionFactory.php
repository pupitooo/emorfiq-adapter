<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use SensitiveParameter;

final readonly class PostgresConnectionFactory
{
    public function __construct(
        private string $dsn,
        private string $username,
        #[SensitiveParameter]
        private string $password,
    ) {}

    /**
     * @throws PDOException
     */
    public function connect(): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }
}
