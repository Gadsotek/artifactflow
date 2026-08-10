<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Application\PageCatalog\PageContentScanner;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\Provenance\ProducerKind;
use Illuminate\Support\Str;

final readonly class VersionProvenanceRules
{
    public const int MAX_PRODUCERS = 8;
    public const int MAX_REFERENCES_PER_PRODUCER = 8;
    public const int MAX_CLAIM_EXTENSIONS_PER_PRODUCER = 16;
    public const int MAX_EXTENSION_KEY_LENGTH = 120;
    public const int MAX_EXTENSION_VALUE_LENGTH = 512;

    private const string FORBIDDEN_EXTENSION_KEY_PATTERN = '/(?:\A|[._-])(?:prompt|system[._-]prompt|chain[._-]of[._-]thought|reasoning|credential|credentials|secret|token|authorization|auth|cookie|header|signed[._-]url|url|payload|blob|content)(?:\z|[._-])/i';

    private const string FORBIDDEN_COMPACT_EXTENSION_KEY_PATTERN = '/(?:prompt|chainofthought|reasoning|credentials?|secret|token|authorization|cookie|header|signedurl|payload|blob|content)/i';

    private const string FORBIDDEN_EXTENSION_VALUE_PATTERN = '/(?:\A|[\r\n])\s*(?:system|developer|user|assistant|tool)\s*:|\b(?:ignore|disregard|override)\b.{0,80}\b(?:instruction|prompt|message|policy)\b|\b(?:system\s+prompt|developer\s+message|chain\s*[-_.]?\s*of\s*[-_.]?\s*thought|(?:hidden|private|internal)\s+reasoning)\b/iu';

    private const string SIGNED_URL_QUERY_PATTERN = '/(?:\A|&)(?:signature|sig|token|access_token|expires|x-amz-signature|x-amz-credential|x-amz-security-token|x-goog-signature|googleaccessid|key-pair-id)=/i';

    public function __construct(private PageContentScanner $scanner)
    {
    }

    public function ensureValid(?VersionProvenanceInput $provenance): void
    {
        if ($provenance === null) {
            return;
        }

        if (count($provenance->producers) > self::MAX_PRODUCERS) {
            throw new DomainRuleViolation(sprintf(
                'Provenance may contain at most %d producers.',
                self::MAX_PRODUCERS,
            ));
        }

        foreach ($provenance->producers as $producer) {
            $this->ensureProducerIsValid($producer);
        }
    }

    private function ensureProducerIsValid(ProducerAssertionInput $producer): void
    {
        if ($producer->kind === ProducerKind::Ai) {
            $this->ensureAiProducerIsValid($producer);
        } elseif ($producer->kind === ProducerKind::Software) {
            $this->ensureSoftwareProducerIsValid($producer);
        } else {
            $this->ensureHumanProducerIsValid($producer);
        }

        $this->ensureOptionalText($producer->modelLabel, 'model label', 191);
        $this->ensureOptionalText($producer->modelVersion, 'model version', 120);
        $this->ensureClaimExtensionsAreValid($producer->claimExtensions);

        if (count($producer->references) > self::MAX_REFERENCES_PER_PRODUCER) {
            throw new DomainRuleViolation(sprintf(
                'A producer may contain at most %d external references.',
                self::MAX_REFERENCES_PER_PRODUCER,
            ));
        }

        foreach ($producer->references as $reference) {
            $this->ensureReferenceIsValid($reference);
        }
    }

    private function ensureAiProducerIsValid(ProducerAssertionInput $producer): void
    {
        if (
            $producer->producerName !== null
            || $producer->producerVersion !== null
        ) {
            throw new DomainRuleViolation(
                'AI provenance cannot declare human or software identity fields.',
            );
        }

        $this->ensureOptionalText($producer->reportedProvider, 'reported provider', 80);
        $this->ensureOptionalText($producer->providerKey, 'provider key', 80);
        $this->ensureOptionalText($producer->modelId, 'model ID', 191);

        if ($producer->reportedProvider !== null && $producer->providerKey === null) {
            throw new DomainRuleViolation('A reported AI provider requires a normalized provider key.');
        }

        if (
            $producer->reportedProvider !== null
            && Str::slug($producer->reportedProvider) !== $producer->providerKey
        ) {
            throw new DomainRuleViolation('The reported AI provider must match its normalized provider key.');
        }

        if (
            $producer->providerKey !== null
            && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $producer->providerKey) !== 1
        ) {
            throw new DomainRuleViolation('AI provenance provider key must be a normalized lowercase slug.');
        }

        if (
            $producer->providerKey === null
            && $producer->modelId === null
            && $producer->modelLabel === null
            && $producer->modelVersion === null
            && $producer->generatedAt === null
            && $producer->references === []
            && $producer->claimExtensions === []
        ) {
            throw new DomainRuleViolation('AI provenance must contain at least one known producer fact.');
        }
    }

    private function ensureSoftwareProducerIsValid(ProducerAssertionInput $producer): void
    {
        if (
            $producer->producerName === null
            || $producer->providerKey !== null
            || $producer->modelId !== null
            || $producer->modelLabel !== null
            || $producer->modelVersion !== null
        ) {
            throw new DomainRuleViolation('Software provenance requires a name and cannot declare AI model fields.');
        }

        $this->ensureRequiredText($producer->producerName, 'software name', 191);
        $this->ensureOptionalText($producer->producerVersion, 'software version', 120);
    }

    private function ensureHumanProducerIsValid(ProducerAssertionInput $producer): void
    {
        if (
            $producer->producerVersion !== null
            || $producer->providerKey !== null
            || $producer->modelId !== null
            || $producer->modelLabel !== null
            || $producer->modelVersion !== null
        ) {
            throw new DomainRuleViolation('Human provenance cannot declare software or AI model fields.');
        }

        $this->ensureOptionalText($producer->producerName, 'human name', 191);
    }

    private function ensureReferenceIsValid(ExternalOriginReferenceInput $reference): void
    {
        $this->ensureOptionalText($reference->externalRef, 'external reference', 512);
        $this->ensureOptionalText($reference->url, 'external reference URL', 2048);

        if ($reference->externalRef === null && $reference->url === null) {
            throw new DomainRuleViolation('A provenance reference needs a reference value or HTTPS URL.');
        }

        if ($reference->url === null) {
            return;
        }

        $parts = parse_url($reference->url);

        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            throw new DomainRuleViolation(
                'Provenance URLs must use HTTPS and must not contain authority credentials.',
            );
        }

        if (
            is_string($parts['query'] ?? null)
            && preg_match(self::SIGNED_URL_QUERY_PATTERN, rawurldecode($parts['query'])) === 1
        ) {
            throw new DomainRuleViolation('Provenance URLs must not be signed capability URLs.');
        }
    }

    /**
     * @param list<ProducerClaimExtension> $extensions
     */
    private function ensureClaimExtensionsAreValid(array $extensions): void
    {
        if (count($extensions) > self::MAX_CLAIM_EXTENSIONS_PER_PRODUCER) {
            throw new DomainRuleViolation(sprintf(
                'A producer may contain at most %d provenance extensions.',
                self::MAX_CLAIM_EXTENSIONS_PER_PRODUCER,
            ));
        }

        foreach ($extensions as $extension) {
            $this->ensureRequiredText(
                $extension->key,
                'extension key',
                self::MAX_EXTENSION_KEY_LENGTH,
            );
            $this->ensureRequiredText(
                $extension->value,
                'extension value',
                self::MAX_EXTENSION_VALUE_LENGTH,
            );
            $compactKey = preg_replace('/[^a-z0-9]+/i', '', $extension->key) ?? '';

            if (
                preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $extension->key) !== 1
                || preg_match(self::FORBIDDEN_EXTENSION_KEY_PATTERN, $extension->key) === 1
                || preg_match(self::FORBIDDEN_COMPACT_EXTENSION_KEY_PATTERN, $compactKey) === 1
            ) {
                throw new DomainRuleViolation('Provenance extensions may contain identity metadata only.');
            }

            if (
                str_contains($extension->value, "\r")
                || str_contains($extension->value, "\n")
                || preg_match(self::FORBIDDEN_EXTENSION_VALUE_PATTERN, $extension->value) === 1
            ) {
                throw new DomainRuleViolation('Provenance extensions may contain identity metadata only.');
            }

            if (preg_match('#(?:https?|wss?)://#i', $extension->value) === 1) {
                throw new DomainRuleViolation(
                    'Provenance extension values cannot contain URLs; use a typed external reference.',
                );
            }
        }
    }

    private function ensureRequiredText(string $value, string $field, int $maximum): void
    {
        if (trim($value) === '') {
            throw new DomainRuleViolation(sprintf('Provenance %s is required.', $field));
        }

        $this->ensureTextIsValid($value, $field, $maximum);
    }

    private function ensureOptionalText(?string $value, string $field, int $maximum): void
    {
        if ($value === null) {
            return;
        }

        if (trim($value) === '') {
            throw new DomainRuleViolation(sprintf('Provenance %s must not be blank.', $field));
        }

        $this->ensureTextIsValid($value, $field, $maximum);
    }

    private function ensureTextIsValid(string $value, string $field, int $maximum): void
    {
        if (!PageContentEncoding::isStorable($value)) {
            throw new DomainRuleViolation(sprintf('Provenance %s contains invalid text.', $field));
        }

        if (mb_strlen($value) > $maximum) {
            throw new DomainRuleViolation(sprintf(
                'Provenance %s must be %d characters or fewer.',
                $field,
                $maximum,
            ));
        }

        $scan = $this->scanner->scanSensitiveMetadata($value);

        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }
    }
}
