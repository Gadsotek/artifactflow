<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareSessionKind;

final class ExternalShareSessionCredential
{
    private const string HASH_CONTEXT = "artifactflow.external-share.session.v1\0";

    public function generate(ExternalShareSessionKind $kind): GeneratedExternalShareSessionCredential
    {
        $bytes = random_bytes(32);

        return new GeneratedExternalShareSessionCredential(
            credential: $this->encode($bytes),
            hash: $this->hashBytes($kind, $bytes),
        );
    }

    public function hashForLookup(
        ExternalShareSessionKind $kind,
        #[\SensitiveParameter]
        string $credential,
    ): ?string {
        $bytes = $this->decode($credential);

        return is_string($bytes) ? $this->hashBytes($kind, $bytes) : null;
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(#[\SensitiveParameter] string $credential): string|false
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $credential) !== 1) {
            return false;
        }

        $decoded = base64_decode(strtr($credential, '-_', '+/') . '=', true);

        if (!is_string($decoded) || strlen($decoded) !== 32 || $this->encode($decoded) !== $credential) {
            return false;
        }

        return $decoded;
    }

    private function hashBytes(ExternalShareSessionKind $kind, #[\SensitiveParameter] string $bytes): string
    {
        return hash('sha256', self::HASH_CONTEXT . $kind->value . "\0" . $bytes);
    }
}
