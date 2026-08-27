<?php

declare(strict_types=1);

namespace App\Application\Mcp;

final readonly class McpToolError implements McpWirePayload
{
    /**
     * @param list<string>|null $findingCodes
     */
    private function __construct(
        public McpToolErrorType $type,
        public string $message,
        public ?bool $retryable = null,
        public ?string $currentVersionUid = null,
        public ?int $currentMetadataRevision = null,
        public ?array $findingCodes = null,
        public ?int $retryAfter = null,
    ) {
    }

    public static function authenticationRequired(string $message): self
    {
        return new self(McpToolErrorType::AuthenticationRequired, $message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(McpToolErrorType::InvalidRequest, $message);
    }

    public static function insufficientScope(string $message): self
    {
        return new self(McpToolErrorType::InsufficientScope, $message);
    }

    public static function notFound(McpNotFoundResource $resource): self
    {
        return new self(
            McpToolErrorType::NotFound,
            sprintf('%s not found.', $resource->value),
        );
    }

    public static function unsupportedContentType(string $message): self
    {
        return new self(McpToolErrorType::UnsupportedContentType, $message);
    }

    public static function contentUnavailable(string $message): self
    {
        return new self(McpToolErrorType::ContentUnavailable, $message);
    }

    public static function contentTooLarge(string $message): self
    {
        return new self(McpToolErrorType::ContentTooLarge, $message);
    }

    public static function versionConflict(string $message, string $currentVersionUid): self
    {
        return new self(
            type: McpToolErrorType::Conflict,
            message: $message,
            retryable: true,
            currentVersionUid: $currentVersionUid,
        );
    }

    public static function metadataConflict(string $message, int $currentMetadataRevision): self
    {
        return new self(
            type: McpToolErrorType::Conflict,
            message: $message,
            retryable: true,
            currentMetadataRevision: $currentMetadataRevision,
        );
    }

    /**
     * @param list<string> $findingCodes
     */
    public static function blockedContent(string $message, array $findingCodes): self
    {
        return new self(
            type: McpToolErrorType::BlockedContent,
            message: $message,
            findingCodes: $findingCodes,
        );
    }

    public static function temporarilyUnavailable(string $message, int $retryAfter): self
    {
        return new self(
            type: McpToolErrorType::TemporarilyUnavailable,
            message: $message,
            retryable: true,
            retryAfter: $retryAfter,
        );
    }

    public static function rateLimited(string $message, int $retryAfter): self
    {
        return new self(
            type: McpToolErrorType::RateLimited,
            message: $message,
            retryAfter: $retryAfter,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'type' => $this->type->value,
            'message' => $this->message,
        ];

        if ($this->retryable !== null) {
            $payload['retryable'] = $this->retryable;
        }

        if ($this->currentVersionUid !== null) {
            $payload['current_version_uid'] = $this->currentVersionUid;
        }

        if ($this->currentMetadataRevision !== null) {
            $payload['current_metadata_revision'] = $this->currentMetadataRevision;
        }

        if ($this->findingCodes !== null) {
            $payload['finding_codes'] = $this->findingCodes;
        }

        if ($this->retryAfter !== null) {
            $payload['retry_after'] = $this->retryAfter;
        }

        return $payload;
    }
}
