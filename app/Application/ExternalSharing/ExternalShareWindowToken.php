<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use LogicException;

final class ExternalShareWindowToken
{
    private const string CONTEXT = "artifactflow.external-share.window.v1\0";

    public function issue(#[\SensitiveParameter] string $viewCredential): string
    {
        return hash_hmac('sha256', self::CONTEXT . $viewCredential, $this->key());
    }

    public function matches(
        #[\SensitiveParameter]
        string $viewCredential,
        #[\SensitiveParameter]
        string $windowToken,
    ): bool {
        return preg_match('/^[a-f0-9]{64}$/D', $windowToken) === 1
            && hash_equals($this->issue($viewCredential), $windowToken);
    }

    private function key(): string
    {
        $key = config('app.key');

        if (!is_string($key) || trim($key) === '') {
            throw new LogicException('Application key is required for external-share window tokens.');
        }

        return $key;
    }
}
