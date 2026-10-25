<?php

declare(strict_types=1);

namespace DevTools\AdapterUi;

use App\Scheduler\Exception\ConfigurationException;
use Dibi\DriverException;
use InvalidArgumentException;
use PDOException;
use Throwable;

final readonly class Api
{
    public function __construct(
        private string $root
    ) {}

    /**
     * @param array<array-key, mixed> $query
     */
    public function run(string $path, array $query): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $data = match ($path) {
                '/api/configuration' => new ConfigurationView($this->root . '/config/scheduler.json')->read(),
                '/api/runs' => new TaskRunHistory()->read($query),
                default => [
                    'error' => 'Neznámý endpoint.',
                ],
            };
            if (! in_array($path, ['/api/configuration', '/api/runs'], true)) {
                http_response_code(404);
            }
            echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $failure) {
            $this->failure($failure);
        }
    }

    private function failure(Throwable $failure): void
    {
        [$status, $message] = match (true) {
            $failure instanceof InvalidArgumentException => [400, $failure->getMessage()],
            $failure instanceof ConfigurationException => [422, $failure->getMessage()],
            ($failure instanceof DriverException || $failure instanceof PDOException)
                && $failure->getCode() === '42P01' => [503,
                    'Tabulka historie zatím neexistuje. Spusťte make migrate.'],
            default => [503, 'Data se nepodařilo načíst. Ověřte dostupnost zdroje a nastavení služby web.'],
        };
        http_response_code($status);
        echo json_encode([
            'error' => $message,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }
}
