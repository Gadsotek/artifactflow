<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Models\InstallationSettings;
use LogicException;

final class InstallationLimitSettings
{
    private ?InstallationLimitValues $cachedValues = null;

    private ?string $cachedConfigSignature = null;

    private bool $cachedFromDatabase = false;

    public function current(): InstallationLimitValues
    {
        if ($this->cachedValues instanceof InstallationLimitValues) {
            if ($this->cachedFromDatabase || $this->cachedConfigSignature === $this->configSignature()) {
                return $this->cachedValues;
            }
        }

        $settings = InstallationSettings::query()
            ->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->orderBy('created_at')
            ->first();

        if ($settings instanceof InstallationSettings) {
            $this->cachedValues = new InstallationLimitValues(
                maxMarkdownBytes: $settings->max_markdown_bytes,
                maxHtmlBytes: $settings->max_html_bytes,
                artifactMaxBytes: $settings->artifact_max_bytes,
                maxWorkspaceStorageBytes: $settings->max_workspace_storage_bytes,
                maxPageStorageBytes: $settings->max_page_storage_bytes,
                maxPageVersions: $settings->max_page_versions,
                maxTagsPerPage: $settings->max_tags_per_page,
                twoFactorRequiredForSystemAdmins: $settings->two_factor_required_for_system_admins,
                twoFactorRequiredForAllUsers: $settings->two_factor_required_for_all_users,
                realtimeEnabled: $settings->realtime_enabled,
            );
            $this->cachedFromDatabase = true;
            $this->cachedConfigSignature = null;

            return $this->cachedValues;
        }

        $this->cachedFromDatabase = false;
        $this->cachedConfigSignature = $this->configSignature();
        $this->cachedValues = new InstallationLimitValues(
            maxMarkdownBytes: $this->configPositiveInt('pages.max_markdown_bytes'),
            maxHtmlBytes: $this->configPositiveInt('pages.max_html_bytes'),
            artifactMaxBytes: $this->configPositiveInt('pages.artifact_max_bytes'),
            maxWorkspaceStorageBytes: $this->configPositiveInt('pages.max_workspace_storage_bytes'),
            maxPageStorageBytes: $this->configPositiveInt('pages.max_page_storage_bytes'),
            maxPageVersions: $this->configPositiveInt('pages.max_page_versions'),
            maxTagsPerPage: $this->configPositiveInt('pages.max_tags_per_page'),
            twoFactorRequiredForSystemAdmins: true,
            twoFactorRequiredForAllUsers: false,
            realtimeEnabled: false,
        );

        return $this->cachedValues;
    }

    public function integer(string $configKey): int
    {
        return $this->current()->valueForConfigKey($configKey);
    }

    public function forgetCachedValues(): void
    {
        $this->cachedValues = null;
        $this->cachedConfigSignature = null;
        $this->cachedFromDatabase = false;
    }

    private function configPositiveInt(string $key): int
    {
        $value = config($key);

        if (is_int($value)) {
            $limit = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $limit = (int) $value;
        } else {
            throw new LogicException(sprintf('Configured installation limit [%s] must be an integer.', $key));
        }

        if ($limit < 1) {
            throw new LogicException(sprintf('Configured installation limit [%s] must be positive.', $key));
        }

        return $limit;
    }

    private function configSignature(): string
    {
        return implode('|', array_map(
            static fn (string $key): string => $key . '=' . var_export(config($key), true),
            [
                'pages.max_markdown_bytes',
                'pages.max_html_bytes',
                'pages.artifact_max_bytes',
                'pages.max_workspace_storage_bytes',
                'pages.max_page_storage_bytes',
                'pages.max_page_versions',
                'pages.max_tags_per_page',
            ],
        ));
    }
}
