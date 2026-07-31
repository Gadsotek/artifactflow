<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final class ExternalShareSecret
{
    private const string HASH_CONTEXT = "artifactflow.external-share.secret.v1\0";

    public function generate(): GeneratedExternalShareSecret
    {
        $bytes = random_bytes(32);

        return new GeneratedExternalShareSecret(
            secret: $this->encode($bytes),
            hash: $this->hashBytes($bytes),
        );
    }

    public function matches(#[\SensitiveParameter] string $secret, string $expectedHash): bool
    {
        $bytes = $this->decode($secret);

        return is_string($bytes)
            && strlen($expectedHash) === 64
            && hash_equals($expectedHash, $this->hashBytes($bytes));
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(#[\SensitiveParameter] string $secret): string|false
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret) !== 1) {
            return false;
        }

        $decoded = base64_decode(strtr($secret, '-_', '+/') . '=', true);

        if (!is_string($decoded) || strlen($decoded) !== 32 || $this->encode($decoded) !== $secret) {
            return false;
        }

        return $decoded;
    }

    private function hashBytes(#[\SensitiveParameter] string $bytes): string
    {
        return hash('sha256', self::HASH_CONTEXT . $bytes);
    }
}
