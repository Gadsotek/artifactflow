<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Support\AuthenticationSessionRevision;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if ($user instanceof User && ($guard === null || $guard === 'web')) {
            $this->withSession([
                AuthenticationSessionRevision::SESSION_KEY => $user->auth_revision,
            ]);
        }

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureConfiguredDatabaseIsIsolated();
        $this->withoutVite();
        config(['app.artifact_url_signing_key' => 'artifact-preview-test-signing-key']);
    }

    /**
     * Refuse to run against anything but an isolated, throwaway test database.
     * Most feature tests use RefreshDatabase; against an inherited local dev
     * database (the framework-standard `php artisan test` / `vendor/bin/pest`
     * path) that would wipe real data. The blessed entrypoint is `make test`,
     * which provisions an `artifactflow_test*` database and drops it afterwards.
     */
    public static function ensureIsolatedTestDatabase(string $database): void
    {
        if (str_starts_with($database, 'artifactflow_test')) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against "%s": it is not an isolated test database (expected a name starting with "artifactflow_test"). Run the suite through `make test` (or `make test TEST_FILTER=Name`), never `php artisan test` or `vendor/bin/pest` directly, because those can inherit the local development database and destroy it.',
            $database,
        ));
    }

    private function ensureConfiguredDatabaseIsIsolated(): void
    {
        $connection = config('database.default');
        $connectionName = is_string($connection) ? $connection : '';
        $database = config(sprintf('database.connections.%s.database', $connectionName));

        static::ensureIsolatedTestDatabase(is_string($database) ? $database : '');
    }

    protected function htmlUploadWithDetectedMime(string $name, string $content, string $detectedMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'artifactflow-html-');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        return new class($path, $name, $detectedMime) extends UploadedFile {
            public function __construct(
                string $path,
                string $name,
                private readonly string $detectedMime,
            ) {
                parent::__construct($path, $name, null, null, true);
            }

            public function getMimeType(): string
            {
                return $this->detectedMime;
            }
        };
    }
}
