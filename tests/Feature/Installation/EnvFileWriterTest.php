<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Application\Installation\EnvFileWriter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnvFileWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/env-fixture-' . Str::random(12));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_it_replaces_an_existing_key_in_place_and_leaves_other_lines_untouched(): void
    {
        file_put_contents($this->path, "# header\nAPP_ENV=local\nAPP_URL=https://app.test\n");

        (new EnvFileWriter($this->path))->upsert(['APP_ENV' => 'production']);

        $this->assertSame(
            "# header\nAPP_ENV=production\nAPP_URL=https://app.test\n",
            (string) file_get_contents($this->path),
        );
    }

    public function test_it_appends_a_missing_key_with_a_trailing_newline(): void
    {
        file_put_contents($this->path, "APP_ENV=local\n");

        (new EnvFileWriter($this->path))->upsert(['TRUSTED_PROXIES' => '10.0.0.1']);

        $this->assertSame(
            "APP_ENV=local\nTRUSTED_PROXIES=10.0.0.1\n",
            (string) file_get_contents($this->path),
        );
    }

    public function test_it_quotes_and_escapes_values_that_need_it(): void
    {
        file_put_contents($this->path, "APP_ENV=local\n");

        (new EnvFileWriter($this->path))->upsert(['DB_PASSWORD' => 'a b"c#d']);

        $this->assertStringContainsString('DB_PASSWORD="a b\\"c#d"', (string) file_get_contents($this->path));
    }

    public function test_it_writes_backreference_sequences_verbatim_when_replacing_a_key(): void
    {
        file_put_contents($this->path, "DB_PASSWORD=old\nAPP_URL=https://app.test\n");

        // $1 / ${2} are backreference syntax in a preg_replace replacement string;
        // they must be written literally, not expanded to (empty) capture groups.
        (new EnvFileWriter($this->path))->upsert(['DB_PASSWORD' => 'a $1 b ${2}']);

        $this->assertSame(
            "DB_PASSWORD=\"a \$1 b \${2}\"\nAPP_URL=https://app.test\n",
            (string) file_get_contents($this->path),
        );
    }

    public function test_it_creates_the_file_when_absent(): void
    {
        $this->assertFalse(is_file($this->path));

        (new EnvFileWriter($this->path))->upsert(['APP_ENV' => 'production']);

        $this->assertSame("APP_ENV=production\n", (string) file_get_contents($this->path));
    }
}
