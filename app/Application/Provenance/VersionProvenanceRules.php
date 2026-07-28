<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Application\PageCatalog\PageContentScanner;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\Provenance\ProducerKind;

final readonly class VersionProvenanceRules
{
    public const int MAX_PRODUCERS = 8;
    public const int MAX_REFERENCES_PER_PRODUCER = 8;

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
            $producer->providerKey === null
            || $producer->modelId === null
            || $producer->producerName !== null
            || $producer->producerVersion !== null
        ) {
            throw new DomainRuleViolation(
                'AI provenance requires a normalized provider key and exact model ID.',
            );
        }

        $this->ensureRequiredText($producer->providerKey, 'provider key', 80);
        $this->ensureRequiredText($producer->modelId, 'model ID', 191);

        if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $producer->providerKey) !== 1) {
            throw new DomainRuleViolation('AI provenance provider key must be a normalized lowercase slug.');
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
