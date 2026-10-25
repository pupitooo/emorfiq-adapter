<?php

declare(strict_types=1);

namespace DevTools\PHPStan;

use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<CallLike>
 */
final readonly class NamedArgumentsRule implements Rule
{
    /**
     * @param list<string> $checkedDirectories
     */
    public function __construct(
        private ApplicationCallResolver $resolver,
        private array $checkedDirectories,
    ) {}

    #[Override]
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable() || ! $this->checksFile($scope->getFile())) {
            return [];
        }
        $arguments = array_values($node->getArgs());
        if ($this->skipsArguments($arguments)) {
            return [];
        }
        $signature = $this->resolveSignature($node, $scope);
        if ($signature === null || ! $this->requiresNames($arguments, $signature)) {
            return [];
        }

        return [RuleErrorBuilder::message(
            'Name every argument unless all arguments are variables matching parameter names exactly.',
        )->identifier('emorfiq.namedArguments')->build()];
    }

    private function checksFile(string $file): bool
    {
        foreach ($this->checkedDirectories as $directory) {
            if (str_starts_with($file, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Arg> $arguments
     */
    private function skipsArguments(array $arguments): bool
    {
        if (count($arguments) <= 1) {
            return true;
        }
        $allNamed = true;
        foreach ($arguments as $argument) {
            // Naming an unpacked or variadic call can change its argument binding.
            if ($argument->unpack) {
                return true;
            }
            $allNamed = $allNamed && $argument->name !== null;
        }

        return $allNamed;
    }

    private function resolveSignature(CallLike $node, Scope $scope): ?ParametersAcceptor
    {
        $reflection = $this->resolver->resolve($node, $scope);
        if ($reflection === null || ! $reflection->acceptsNamedArguments()->yes()) {
            return null;
        }
        $signature = ParametersAcceptorSelector::selectFromArgs(
            $scope,
            $node->getArgs(),
            $reflection->getVariants(),
            $reflection->getNamedArgumentsVariants(),
        );

        return $signature->isVariadic() ? null : $signature;
    }

    /**
     * @param list<Arg> $arguments
     */
    private function requiresNames(array $arguments, ParametersAcceptor $signature): bool
    {
        $parameters = $signature->getParameters();
        foreach ($arguments as $index => $argument) {
            if ($argument->name !== null || ! $this->matchesParameter($argument, $parameters[$index] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function matchesParameter(Arg $argument, ?ParameterReflection $parameter): bool
    {
        return $parameter !== null
            && $argument->value instanceof Variable
            && $argument->value->name === $parameter->getName();
    }
}
