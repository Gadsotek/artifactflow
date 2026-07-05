<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;

/**
 * Typed, validating accessor over an MCP tool's raw argument object. Keeps the
 * boundary coercion out of the controller so tool workflows read intent, not
 * array plumbing.
 */
final class McpToolArguments
{
    /**
     * @param array<string, mixed> $arguments
     */
    private function __construct(private readonly array $arguments)
    {
    }

    public static function fromValue(mixed $value, string $name): self
    {
        return new self(self::stringKeyed($value, $name));
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyed(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new DomainRuleViolation(sprintf('Argument [%s] must be an object.', $name));
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new DomainRuleViolation(sprintf('Argument [%s] must be an object.', $name));
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function requiredString(string $key): string
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            throw new DomainRuleViolation(sprintf('Argument [%s] is required.', $key));
        }

        return $value;
    }

    public function string(string $key, string $default): string
    {
        return $this->nullableString($key) ?? $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->arguments[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new DomainRuleViolation(sprintf('Argument [%s] must be a string.', $key));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->arguments[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new DomainRuleViolation(sprintf('Argument [%s] must be a boolean.', $key));
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->arguments[$key] ?? [];

        if (!is_array($value)) {
            throw new DomainRuleViolation(sprintf('Argument [%s] must be a list of strings.', $key));
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new DomainRuleViolation(sprintf('Argument [%s] must be a list of strings.', $key));
            }

            $item = trim($item);

            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return array_values(array_unique($strings));
    }

    public function requiredPageType(string $key): PageType
    {
        $type = $this->pageType($key);

        if (!$type instanceof PageType) {
            throw new DomainRuleViolation(sprintf('Argument [%s] is required.', $key));
        }

        return $type;
    }

    public function pageType(string $key): ?PageType
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        $type = PageType::tryFrom($value);

        if (!$type instanceof PageType) {
            throw new DomainRuleViolation(sprintf('Argument [%s] has an unsupported page type.', $key));
        }

        return $type;
    }

    public function pageStatus(string $key): ?PageStatus
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        $status = PageStatus::tryFrom($value);

        if (!$status instanceof PageStatus) {
            throw new DomainRuleViolation(sprintf('Argument [%s] has an unsupported page status.', $key));
        }

        return $status;
    }
}
