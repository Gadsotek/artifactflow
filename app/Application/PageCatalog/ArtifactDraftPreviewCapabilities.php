<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;

final class ArtifactDraftPreviewCapabilities
{
    private const string PURPOSE = 'draft-preview';

    private const string SIGNATURE_CONTEXT = "artifactflow-draft-preview-capability-v1\n";

    private const int VERSION = 1;

    public function __construct(
        private readonly ArtifactPreviewConfiguration $configuration,
    ) {
    }

    public function issue(string $workspaceUid, int $contentBytes, string $contentSha256): ArtifactDraftPreviewCapability
    {
        if (!$this->validWorkspaceUid($workspaceUid)) {
            throw new InvalidArgumentException('Draft preview capability workspace must be a ULID.');
        }

        if ($contentBytes < 1) {
            throw new InvalidArgumentException('Draft preview capability content length must be positive.');
        }

        if (!$this->validContentHash($contentSha256)) {
            throw new InvalidArgumentException('Draft preview capability content hash must be lowercase SHA-256.');
        }

        $expiresAt = Carbon::now()->addSeconds($this->configuration->ttlSeconds())->getTimestamp();
        $payload = json_encode([
            'v' => self::VERSION,
            'purpose' => self::PURPOSE,
            'origin' => $this->configuration->artifactOrigin(),
            'workspace_uid' => $workspaceUid,
            'expires' => $expiresAt,
            'nonce' => bin2hex(random_bytes(16)),
            'content_bytes' => $contentBytes,
            'content_sha256' => $contentSha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = $this->signature($encodedPayload);

        return new ArtifactDraftPreviewCapability($encodedPayload . '.' . $signature, $expiresAt);
    }

    public function matches(string $token, string $content): bool
    {
        $claims = $this->claimsFrom($token);

        if ($claims === null) {
            return false;
        }

        if ($claims['content_bytes'] !== strlen($content)) {
            return false;
        }

        return hash_equals($claims['content_sha256'], hash('sha256', $content));
    }

    public function hasValidEnvelope(string $token): bool
    {
        return $this->claimsFrom($token) !== null;
    }

    /**
     * @return array{
     *     v: int,
     *     purpose: string,
     *     origin: string,
     *     workspace_uid: string,
     *     expires: int,
     *     nonce: string,
     *     content_bytes: int,
     *     content_sha256: string
     * }|null
     */
    private function claimsFrom(string $token): ?array
    {
        if (strlen($token) > 768) {
            return null;
        }

        $matched = preg_match('/\A([A-Za-z0-9_-]{1,640})\.([a-f0-9]{64})\z/', $token, $parts);

        if ($matched !== 1) {
            return null;
        }

        $encodedPayload = $parts[1];
        $signature = $parts[2];

        if (!hash_equals($this->signature($encodedPayload), $signature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($encodedPayload);

        if ($payload === null) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $expectedKeys = [
            'v',
            'purpose',
            'origin',
            'workspace_uid',
            'expires',
            'nonce',
            'content_bytes',
            'content_sha256',
        ];

        if (!is_array($decoded) || array_keys($decoded) !== $expectedKeys) {
            return null;
        }

        $version = $decoded['v'];
        $purpose = $decoded['purpose'];
        $origin = $decoded['origin'];
        $workspaceUid = $decoded['workspace_uid'];
        $expiresAt = $decoded['expires'];
        $nonce = $decoded['nonce'];
        $contentBytes = $decoded['content_bytes'];
        $contentSha256 = $decoded['content_sha256'];

        if (
            $version !== self::VERSION
            || $purpose !== self::PURPOSE
            || !is_string($origin)
            || !hash_equals($this->configuration->artifactOrigin(), $origin)
            || !is_string($workspaceUid)
            || !$this->validWorkspaceUid($workspaceUid)
            || !is_int($expiresAt)
            || $expiresAt < Carbon::now()->getTimestamp()
            || $expiresAt > Carbon::now()->addSeconds(ArtifactPreviewConfiguration::MAX_TTL_SECONDS)->getTimestamp()
            || !is_string($nonce)
            || preg_match('/\A[a-f0-9]{32}\z/', $nonce) !== 1
            || !is_int($contentBytes)
            || $contentBytes < 1
            || !is_string($contentSha256)
            || !$this->validContentHash($contentSha256)
        ) {
            return null;
        }

        return [
            'v' => $version,
            'purpose' => $purpose,
            'origin' => $origin,
            'workspace_uid' => $workspaceUid,
            'expires' => $expiresAt,
            'nonce' => $nonce,
            'content_bytes' => $contentBytes,
            'content_sha256' => $contentSha256,
        ];
    }

    private function signature(string $encodedPayload): string
    {
        return hash_hmac(
            'sha256',
            self::SIGNATURE_CONTEXT . $encodedPayload,
            $this->configuration->signingKey(),
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function validWorkspaceUid(string $workspaceUid): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $workspaceUid) === 1;
    }

    private function validContentHash(string $contentSha256): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $contentSha256) === 1;
    }
}
