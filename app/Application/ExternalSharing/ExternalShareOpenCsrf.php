<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\ExternalShareSession;
use Carbon\CarbonImmutable;
use LogicException;

final class ExternalShareOpenCsrf
{
    private const string CONTEXT = "artifactflow.external-share.open-csrf.v1\0";

    public function issue(ExternalShareSession $session): string
    {
        return $this->token($session);
    }

    public function matches(ExternalShareSession $session, #[\SensitiveParameter] string $token): bool
    {
        return strlen($token) === 64 && hash_equals($this->token($session), $token);
    }

    private function token(ExternalShareSession $session): string
    {
        $key = config('app.key');
        $expiresAt = $session->expires_at;

        if (!is_string($key) || trim($key) === '') {
            throw new LogicException('Application key is required for external-share CSRF tokens.');
        }

        if (!$expiresAt instanceof CarbonImmutable) {
            throw new LogicException('Pending external-share sessions require an expiry.');
        }

        return hash_hmac(
            'sha256',
            self::CONTEXT . $session->uid . "\0" . $expiresAt->getTimestamp(),
            $key,
        );
    }
}
