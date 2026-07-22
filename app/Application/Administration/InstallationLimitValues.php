<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\DomainRuleViolation;

final readonly class InstallationLimitValues
{
    public function __construct(
        public int $maxMarkdownBytes,
        public int $maxHtmlBytes,
        public int $artifactMaxBytes,
        public int $maxWorkspaceStorageBytes,
        public int $maxPageStorageBytes,
        public int $maxPageVersions,
        public int $maxTagsPerPage,
        public bool $twoFactorRequiredForSystemAdmins = true,
        public bool $twoFactorRequiredForAllUsers = false,
        public bool $realtimeEnabled = false,
    ) {
        if ($this->maxMarkdownBytes > InstallationLimitCeilings::CONTENT_BYTES) {
            throw new DomainRuleViolation('Markdown write limit must not exceed the HTTP request envelope.');
        }

        if ($this->maxHtmlBytes > InstallationLimitCeilings::CONTENT_BYTES) {
            throw new DomainRuleViolation('HTML write limit must not exceed the production HTTP request envelope.');
        }

        if ($this->artifactMaxBytes < max($this->maxHtmlBytes, $this->maxMarkdownBytes)) {
            throw new DomainRuleViolation(
                'Artifact read limit must be greater than or equal to every content write limit.',
            );
        }
    }

    /**
     * @return array<string, bool|int>
     */
    public function toPersistenceArray(): array
    {
        return [
            'max_markdown_bytes' => $this->maxMarkdownBytes,
            'max_html_bytes' => $this->maxHtmlBytes,
            'artifact_max_bytes' => $this->artifactMaxBytes,
            'max_workspace_storage_bytes' => $this->maxWorkspaceStorageBytes,
            'max_page_storage_bytes' => $this->maxPageStorageBytes,
            'max_page_versions' => $this->maxPageVersions,
            'max_tags_per_page' => $this->maxTagsPerPage,
            'two_factor_required_for_system_admins' => $this->twoFactorRequiredForSystemAdmins,
            'two_factor_required_for_all_users' => $this->twoFactorRequiredForAllUsers,
            'realtime_enabled' => $this->realtimeEnabled,
        ];
    }

    public function valueForConfigKey(string $key): int
    {
        return match ($key) {
            'pages.max_markdown_bytes' => $this->maxMarkdownBytes,
            'pages.max_html_bytes' => $this->maxHtmlBytes,
            'pages.artifact_max_bytes' => $this->artifactMaxBytes,
            'pages.max_workspace_storage_bytes' => $this->maxWorkspaceStorageBytes,
            'pages.max_page_storage_bytes' => $this->maxPageStorageBytes,
            'pages.max_page_versions' => $this->maxPageVersions,
            'pages.max_tags_per_page' => $this->maxTagsPerPage,
            default => throw new \InvalidArgumentException(sprintf('Unknown installation limit [%s].', $key)),
        };
    }
}
