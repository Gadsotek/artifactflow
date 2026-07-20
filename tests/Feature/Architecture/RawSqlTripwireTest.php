<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

final class RawSqlTripwireTest extends TestCase
{
    private const array RAW_METHODS = [
        'fromraw',
        'groupbyraw',
        'havingraw',
        'orhavingraw',
        'orwhereraw',
        'orderbyraw',
        'selectraw',
        'whereraw',
    ];

    public function test_runtime_raw_sql_uses_compile_time_fragments_and_bound_values(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('routes'), database_path()] as $root) {
            foreach (new Finder()->in($root)->name('*.php')->files() as $file) {
                if (!$this->containsOnlySafeRawCalls($file->getContents())) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getRealPath());
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'Raw SQL first arguments must be non-interpolated literals or class constants; bind every runtime value. Offenders: '
                . implode(', ', $offenders),
        );
    }

    public function test_tripwire_rejects_dynamic_interpolated_and_concatenated_fragments(): void
    {
        foreach ([
            '$query->whereRaw($sql, [$value]);',
            '$query->selectRaw("score = {$score}");',
            '$query->orderByRaw(\'position(\' . $needle . \' in title)\');',
            '$query->whereRaw /* formatting cannot bypass the tripwire */ ($sql);',
            'DB::raw($column);',
        ] as $source) {
            $this->assertFalse($this->containsOnlySafeRawCalls('<?php ' . $source), $source);
        }
    }

    public function test_tripwire_accepts_literals_class_constants_and_bindings(): void
    {
        foreach ([
            '$query->whereRaw(\'LOWER(email) = ?\', [$email]);',
            '$query->selectRaw(self::SEARCH_RANK_SQL, [$search]);',
            '$query->orderByRaw(SearchSql::ORDER);',
            'DB::raw("LOWER(title)");',
        ] as $source) {
            $this->assertTrue($this->containsOnlySafeRawCalls('<?php ' . $source), $source);
        }
    }

    private function containsOnlySafeRawCalls(string $source): bool
    {
        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        } catch (Error) {
            return false;
        }

        if ($statements === null) {
            return false;
        }

        $calls = (new NodeFinder())->find(
            $statements,
            fn (Node $node): bool => $this->isRawSqlCall($node),
        );

        foreach ($calls as $call) {
            if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
                continue;
            }

            $arguments = $call->getArgs();
            $firstArgument = $arguments[0]->value ?? null;
            $isLiteral = $firstArgument instanceof String_;
            $isNamedClassConstant = $firstArgument instanceof ClassConstFetch
                && $firstArgument->class instanceof Name
                && $firstArgument->name instanceof Identifier;

            if (!$isLiteral && !$isNamedClassConstant) {
                return false;
            }
        }

        return true;
    }

    private function isRawSqlCall(Node $node): bool
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            return in_array(strtolower($node->name->name), self::RAW_METHODS, true);
        }

        return $node instanceof StaticCall
            && $node->class instanceof Name
            && strtolower($node->class->getLast()) === 'db'
            && $node->name instanceof Identifier
            && strtolower($node->name->name) === 'raw';
    }
}
