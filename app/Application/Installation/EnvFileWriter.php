<?php

declare(strict_types=1);

namespace App\Application\Installation;

/**
 * Minimal, dependency-free writer for the deployment .env file used by the
 * install wizard to persist the chosen APP_ENV and any production boot-gate
 * values the operator supplies. It upserts individual keys in place and leaves
 * every other line (comments, ordering, unrelated keys) untouched, so re-running
 * install never rewrites the whole file.
 */
final readonly class EnvFileWriter
{
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function upsert(array $values): void
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $contents = $this->upsertOne($contents, $key, $value);
        }

        file_put_contents($this->path, $contents);
    }

    private function upsertOne(string $contents, string $key, string $value): string
    {
        $line = $key . '=' . $this->encode($value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            // preg_replace_callback (not preg_replace) so a value containing $1, \0,
            // or ${x} is written verbatim instead of being interpreted as a
            // backreference in the replacement string.
            $replaced = preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);

            return is_string($replaced) ? $replaced : $contents;
        }

        $prefix = $contents === '' || str_ends_with($contents, "\n") ? '' : "\n";

        return $contents . $prefix . $line . "\n";
    }

    private function encode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Quote (and escape) only when the value carries characters a bare .env
        // token cannot: whitespace, a comment marker, or an embedded quote.
        if (preg_match('/[\s#"\']/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
