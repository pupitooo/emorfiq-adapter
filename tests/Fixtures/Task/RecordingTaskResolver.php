<?php

declare(strict_types=1);

namespace Tests\Fixtures\Task;

use App\Task\Contract\Task;
use App\Task\Contract\TaskResolver;
use Closure;
use Override;

final class RecordingTaskResolver implements TaskResolver
{
    /**
     * @var list<string>
     */
    public private(set) array $resolved = [];

    /**
     * @var Closure(string): void|null
     */
    private ?Closure $beforeResolve = null;

    public function __construct(
        private readonly TaskResolver $resolver,
    ) {}

    /**
     * @param Closure(string): void $callback
     */
    public function onResolve(Closure $callback): void
    {
        $this->beforeResolve = $callback;
    }

    #[Override]
    public function resolve(string $taskClass): Task
    {
        $this->resolved[] = $taskClass;
        if ($this->beforeResolve !== null) {
            ($this->beforeResolve)($taskClass);
        }

        return $this->resolver->resolve($taskClass);
    }
}
