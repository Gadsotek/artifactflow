<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Serializes use and revocation of one MCP credential without holding a
 * database transaction open across parser, scanner, or storage work.
 */
final readonly class McpAccessTokenExecutionLock
{
    /**
     * @template TResult
     *
     * @param Closure(): TResult $run
     *
     * @return TResult
     */
    public function runShared(string $tokenUid, Closure $run): mixed
    {
        return $this->runWithLock($tokenUid, $run, true);
    }

    /**
     * @template TResult
     *
     * @param Closure(): TResult $run
     *
     * @return TResult
     */
    public function runExclusive(string $tokenUid, Closure $run): mixed
    {
        return $this->runWithLock($tokenUid, $run, false);
    }

    /**
     * @template TResult
     *
     * @param Closure(): TResult $run
     *
     * @return TResult
     */
    private function runWithLock(string $tokenUid, Closure $run, bool $shared): mixed
    {
        [$firstKey, $secondKey] = $this->lockKeys($tokenUid);
        $acquireStatement = $shared
            ? 'SELECT pg_advisory_lock_shared(?, ?)'
            : 'SELECT pg_advisory_lock(?, ?)';
        $releaseStatement = $shared
            ? 'SELECT pg_advisory_unlock_shared(?, ?)'
            : 'SELECT pg_advisory_unlock(?, ?)';
        DB::select($acquireStatement, [$firstKey, $secondKey]);

        try {
            return $run();
        } finally {
            DB::select($releaseStatement, [$firstKey, $secondKey]);
        }
    }

    /**
     * Principal-wide revocation acquires token locks in stable order so it
     * cannot deadlock with another principal-wide revocation.
     *
     * @template TResult
     *
     * @param list<string> $tokenUids
     * @param Closure(): TResult $run
     *
     * @return TResult
     */
    public function runManyExclusive(array $tokenUids, Closure $run): mixed
    {
        $tokenUids = array_values(array_unique($tokenUids));
        sort($tokenUids, SORT_STRING);

        return $this->runAtOffset($tokenUids, 0, $run);
    }

    /**
     * @template TResult
     *
     * @param list<string> $tokenUids
     * @param Closure(): TResult $run
     *
     * @return TResult
     */
    private function runAtOffset(array $tokenUids, int $offset, Closure $run): mixed
    {
        if (!isset($tokenUids[$offset])) {
            return $run();
        }

        return $this->runExclusive(
            $tokenUids[$offset],
            fn (): mixed => $this->runAtOffset($tokenUids, $offset + 1, $run),
        );
    }

    /**
     * @return array{int, int}
     */
    private function lockKeys(string $tokenUid): array
    {
        $digest = hash('sha256', 'artifactflow:mcp-token-execution:' . $tokenUid, true);
        $keys = unpack('Nfirst/Nsecond', $digest);
        $firstKey = is_array($keys) ? ($keys['first'] ?? null) : null;
        $secondKey = is_array($keys) ? ($keys['second'] ?? null) : null;

        if (!is_int($firstKey) || !is_int($secondKey)) {
            throw new LogicException('Unable to derive the MCP token execution lock.');
        }

        return [
            $this->signedInt32($firstKey),
            $this->signedInt32($secondKey),
        ];
    }

    private function signedInt32(int $value): int
    {
        return $value > 2_147_483_647 ? $value - 4_294_967_296 : $value;
    }
}
