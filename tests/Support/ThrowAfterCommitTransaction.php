<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use PDOException;
use Throwable;

final class ThrowAfterCommitTransaction
{
    public static function install(string $message): void
    {
        $manager = DB::getFacadeRoot();
        if (!$manager instanceof DatabaseManager) {
            throw new LogicException('The database facade root is unavailable.');
        }

        $connection = $manager->connection();
        $proxy = Mockery::mock($manager);
        $proxy->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (callable $callback) use ($connection, $manager, $message): never {
                $connection->beginTransaction();

                try {
                    $callback();
                } catch (Throwable $exception) {
                    $connection->rollBack();
                    DB::swap($manager);

                    throw $exception;
                }

                try {
                    $connection->commit();
                } finally {
                    DB::swap($manager);
                }

                $exception = new PDOException($message);
                $exception->errorInfo = ['08007', 0, 'transaction_resolution_unknown'];

                throw $exception;
            });
        DB::swap($proxy);
    }
}
