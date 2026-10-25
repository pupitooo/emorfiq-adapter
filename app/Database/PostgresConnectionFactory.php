<?php

declare(strict_types=1);

namespace App\Database;

use Dibi\Connection;
use Dibi\Exception;
use PDO;
use PDOException;
use RuntimeException;
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
     * @throws RuntimeException
     */
    public static function fromEnvironment(): self
    {
        return new self(
            self::environment('DATABASE_DSN'),
            self::environment('DATABASE_USER'),
            self::environment('DATABASE_PASSWORD'),
        );
    }

    /**
     * @throws Exception
     */
    public function connect(): Connection
    {
        return new Connection([
            'driver' => 'pdo',
            'dsn' => $this->dsn,
            'username' => $this->username,
            'password' => $this->password,
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
            'result' => [
                'formatDateTime' => 'native',
            ],
        ]);
    }

    /**
     * @throws Exception
     * @throws PDOException
     */
    public static function wrap(PDO $connection): Connection
    {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        return new Connection([
            'driver' => 'pdo',
            'resource' => $connection,
            'result' => [
                'formatDateTime' => 'native',
            ],
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private static function environment(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new RuntimeException('Missing database connection setting: ' . $key);
        }

        return $value;
    }
}
