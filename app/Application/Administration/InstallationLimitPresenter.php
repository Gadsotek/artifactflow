<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class InstallationLimitPresenter
{
    public function __construct(
        private InstallationLimitSettings $settings,
        private ByteSizeFormatter $bytes,
    ) {
    }

    public function currentValues(): InstallationLimitValues
    {
        return $this->settings->current();
    }

    /**
     * @return list<InstallationLimitViewItem>
     */
    public function viewItems(): array
    {
        $values = $this->settings->current();

        return [
            $this->byteItem(
                name: 'max_markdown_bytes',
                label: 'Markdown page size',
                description: 'Maximum accepted for one Markdown page version or preview request.',
                value: $values->maxMarkdownBytes,
                maxValue: InstallationLimitCeilings::CONTENT_BYTES,
            ),
            $this->byteItem(
                name: 'max_html_bytes',
                label: 'HTML artifact size',
                description: 'Maximum accepted for one pasted or uploaded HTML artifact version.',
                value: $values->maxHtmlBytes,
                maxValue: InstallationLimitCeilings::CONTENT_BYTES,
            ),
            $this->byteItem(
                name: 'artifact_max_bytes',
                label: 'Artifact preview read size',
                description: 'Maximum stored artifact bytes the isolated artifact runtime will read for preview.',
                value: $values->artifactMaxBytes,
                maxValue: InstallationLimitCeilings::ARTIFACT_READ_BYTES,
            ),
            $this->byteItem(
                name: 'max_workspace_storage_bytes',
                label: 'Workspace storage',
                description: 'Maximum total page-version storage allowed in each workspace.',
                value: $values->maxWorkspaceStorageBytes,
                maxValue: InstallationLimitCeilings::WORKSPACE_STORAGE_BYTES,
            ),
            $this->byteItem(
                name: 'max_page_storage_bytes',
                label: 'Single page storage',
                description: 'Maximum total page-version storage allowed for one page.',
                value: $values->maxPageStorageBytes,
                maxValue: InstallationLimitCeilings::PAGE_STORAGE_BYTES,
            ),
            new InstallationLimitViewItem(
                name: 'max_page_versions',
                label: 'Single page versions',
                description: 'Maximum number of immutable versions retained for one page.',
                value: $values->maxPageVersions,
                displayValue: (string) $values->maxPageVersions,
                unit: 'versions',
                maxValue: InstallationLimitCeilings::PAGE_VERSIONS,
                maxDisplayValue: (string) InstallationLimitCeilings::PAGE_VERSIONS,
            ),
            new InstallationLimitViewItem(
                name: 'max_tags_per_page',
                label: 'Tags per page',
                description: 'Maximum number of tags accepted on one page.',
                value: $values->maxTagsPerPage,
                displayValue: (string) $values->maxTagsPerPage,
                unit: 'tags',
                maxValue: InstallationLimitCeilings::TAGS_PER_PAGE,
                maxDisplayValue: (string) InstallationLimitCeilings::TAGS_PER_PAGE,
            ),
        ];
    }

    private function byteItem(
        string $name,
        string $label,
        string $description,
        int $value,
        int $maxValue,
    ): InstallationLimitViewItem {
        [$displayAmount, $displayUnit] = $this->readableByteInput($value);

        return new InstallationLimitViewItem(
            name: $name,
            label: $label,
            description: $description,
            value: $value,
            displayValue: $this->bytes->format($value),
            unit: 'bytes',
            maxValue: $maxValue,
            maxDisplayValue: $this->bytes->format($maxValue),
            displayAmount: $displayAmount,
            displayUnit: $displayUnit,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readableByteInput(int $bytes): array
    {
        foreach ([
            'GiB' => 1024 * 1024 * 1024,
            'MiB' => 1024 * 1024,
            'KiB' => 1024,
        ] as $unit => $multiplier) {
            if ($bytes >= $multiplier) {
                return [$this->formatAmount($bytes / $multiplier), $unit];
            }
        }

        return [(string) $bytes, 'B'];
    }

    private function formatAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
