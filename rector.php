<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector;
use RectorLaravel\Rector\ClassMethod\AddParentBootToModelClassMethodRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap/app.php',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withRules([
        AddGenericReturnTypeToRelationsRector::class,
        AddParentBootToModelClassMethodRector::class,
        DeclareStrictTypesRector::class,
        RemoveDumpDataDeadCodeRector::class,
        RemoveUnusedPrivateMethodRector::class,
    ]);
