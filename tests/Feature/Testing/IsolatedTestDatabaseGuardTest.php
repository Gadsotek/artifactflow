<?php

declare(strict_types=1);

use Tests\TestCase;

/**
 * The Makefile `make test` wrapper points the suite at an isolated, throwaway
 * database named `artifactflow_test*`. Running the framework-standard
 * `php artisan test` / `vendor/bin/pest` directly would inherit the local dev
 * database and `RefreshDatabase` would destroy it. TestCase must therefore
 * refuse to run against any database whose name does not match the isolated
 * test pattern.
 */
it('accepts isolated test database names', function (string $database): void {
    expect(fn () => TestCase::ensureIsolatedTestDatabase($database))
        ->not->toThrow(Throwable::class);
})->with([
    'wrapper database' => 'artifactflow_test_0f19a2b3c4d5e6f708192a3b4c5d6e7f',
    'wrapper e2e database' => 'artifactflow_test_e2e_0f19a2b3c4d5e6f708192a3b4c5d6e7f',
    'phpunit fallback database' => 'artifactflow_testing',
    'plain test database' => 'artifactflow_test',
]);

it('refuses non-isolated database names', function (string $database): void {
    expect(fn () => TestCase::ensureIsolatedTestDatabase($database))
        ->toThrow(RuntimeException::class, 'isolated test database');
})->with([
    'local dev database' => 'artifactflow',
    'postgres default database' => 'postgres',
    'production-looking database' => 'artifactflow_production',
    'prefix trick' => 'artifactflow_dev_test',
    'empty name' => '',
]);

it('runs the suite against a database that satisfies the guard', function (): void {
    $connection = config('database.default');
    $database = config(sprintf('database.connections.%s.database', is_string($connection) ? $connection : ''));

    expect($database)->toBeString();
    expect(fn () => TestCase::ensureIsolatedTestDatabase(is_string($database) ? $database : ''))
        ->not->toThrow(Throwable::class);
});
