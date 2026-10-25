<?php

declare(strict_types=1);

namespace DevTools\PHPStan;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

/**
 * Resolves native application declarations, leaving library and ambiguous dynamic calls alone.
 */
final readonly class ApplicationCallResolver
{
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private string $applicationDirectory,
    ) {}

    public function resolve(CallLike $node, Scope $scope): ExtendedMethodReflection|FunctionReflection|null
    {
        if ($node instanceof FuncCall) {
            return $this->resolveFunction($node, $scope);
        }
        if ($node instanceof New_) {
            return $this->resolveMethod($scope->getType($node), '__construct');
        }
        if (! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall && ! $node instanceof StaticCall) {
            return null;
        }
        if (! $node->name instanceof Identifier) {
            return null;
        }

        return $this->resolveMethod($this->receiverType($node, $scope), $node->name->toString());
    }

    private function resolveFunction(FuncCall $node, Scope $scope): ?FunctionReflection
    {
        if (! $node->name instanceof Name || ! $this->reflectionProvider->hasFunction($node->name, $scope)) {
            return null;
        }
        $reflection = $this->reflectionProvider->getFunction($node->name, $scope);

        return $this->isApplicationFile($reflection->getFileName()) ? $reflection : null;
    }

    private function resolveMethod(Type $type, string $methodName): ?ExtendedMethodReflection
    {
        $classes = $type->getObjectClassReflections();
        // A union receiver may resolve to declarations with different parameter names.
        if (count($classes) !== 1 || ! $classes[0]->hasNativeMethod($methodName)) {
            return null;
        }
        $reflection = $classes[0]->getNativeMethod($methodName);

        return $this->isApplicationFile($reflection->getDeclaringClass()->getFileName()) ? $reflection : null;
    }

    private function receiverType(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): Type
    {
        if (! $node instanceof StaticCall) {
            return $scope->getType($node->var);
        }
        if ($node->class instanceof Name) {
            return $scope->resolveTypeByName($node->class);
        }

        return $scope->getType($node->class)->getObjectTypeOrClassStringObjectType();
    }

    private function isApplicationFile(?string $file): bool
    {
        return $file !== null && str_starts_with($file, $this->applicationDirectory . '/');
    }
}
