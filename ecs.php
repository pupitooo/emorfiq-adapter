<?php

declare(strict_types=1);

use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Metrics\CyclomaticComplexitySniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Metrics\NestingLevelSniff;
use PhpCsFixer\Fixer\ClassNotation\FinalClassFixer;
use PhpCsFixer\Fixer\FunctionNotation\NullableTypeDeclarationForDefaultNullValueFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\Strict\StrictParamFixer;
use SlevomatCodingStandard\Sniffs\Classes\ClassLengthSniff;
use SlevomatCodingStandard\Sniffs\Classes\ReadonlyClassSniff;
use SlevomatCodingStandard\Sniffs\Complexity\CognitiveSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\AssignmentInConditionSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\EarlyExitSniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\Functions\UnusedParameterSniff;
use SlevomatCodingStandard\Sniffs\Variables\UnusedVariableSniff;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bin',
        __DIR__ . '/config',
        __DIR__ . '/tests',
        __DIR__ . '/tools/log-viewer',
        __FILE__,
    ])
    ->withPreparedSets(perCs: true, common: true, cleanCode: true)
    ->withRules([
        DeclareStrictTypesFixer::class,
        StrictParamFixer::class,
        NullableTypeDeclarationForDefaultNullValueFixer::class,
        FinalClassFixer::class,
        ReadonlyClassSniff::class,
        AssignmentInConditionSniff::class,
        EarlyExitSniff::class,
        UnusedParameterSniff::class,
        UnusedVariableSniff::class,
    ])
    ->withConfiguredRule(LineLengthSniff::class, [
        'lineLimit' => 120,
        'absoluteLineLimit' => 120,
        'ignoreComments' => false,
    ])
    ->withConfiguredRule(CyclomaticComplexitySniff::class, [
        'complexity' => 10,
        'absoluteComplexity' => 10,
    ])
    ->withConfiguredRule(NestingLevelSniff::class, [
        'nestingLevel' => 5,
        'absoluteNestingLevel' => 5,
    ])
    ->withConfiguredRule(CognitiveSniff::class, [
        'warningThreshold' => 6,
        'errorThreshold' => 6,
    ])
    ->withConfiguredRule(FunctionLengthSniff::class, [
        'maxLinesLength' => 20,
    ])
    ->withConfiguredRule(ClassLengthSniff::class, [
        'maxLinesLength' => 250,
    ]);
