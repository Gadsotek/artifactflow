<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Application\Administration\InstallationLimitCeilings;
use App\Application\Administration\InstallationLimitValues;
use App\Http\Requests\AppFormRequest;
use LogicException;

final class UpdateInstallationLimitsRequest extends AppFormRequest
{
    private const array BYTE_FIELDS = [
        'max_markdown_bytes',
        'max_html_bytes',
        'artifact_max_bytes',
        'max_workspace_storage_bytes',
        'max_page_storage_bytes',
    ];

    private const array BYTE_UNIT_MULTIPLIERS = [
        'B' => 1,
        'KiB' => 1024,
        'MiB' => 1024 * 1024,
        'GiB' => 1024 * 1024 * 1024,
    ];

    public function authorize(): bool
    {
        return $this->authenticatedUserIsSystemAdmin();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'max_markdown_bytes' => ['required', 'integer', 'min:1', 'max:' . InstallationLimitCeilings::CONTENT_BYTES],
            'max_html_bytes' => ['required', 'integer', 'min:1', 'max:' . InstallationLimitCeilings::CONTENT_BYTES],
            'artifact_max_bytes' => [
                'required',
                'integer',
                'min:1',
                'max:' . InstallationLimitCeilings::ARTIFACT_READ_BYTES,
                'gte:max_html_bytes',
            ],
            'max_workspace_storage_bytes' => [
                'required',
                'integer',
                'min:1',
                'max:' . InstallationLimitCeilings::WORKSPACE_STORAGE_BYTES,
            ],
            'max_page_storage_bytes' => [
                'required',
                'integer',
                'min:1',
                'max:' . InstallationLimitCeilings::PAGE_STORAGE_BYTES,
            ],
            'max_page_versions' => ['required', 'integer', 'min:1', 'max:' . InstallationLimitCeilings::PAGE_VERSIONS],
            'max_tags_per_page' => ['required', 'integer', 'min:1', 'max:' . InstallationLimitCeilings::TAGS_PER_PAGE],
            'two_factor_required_for_system_admins' => ['nullable', 'boolean'],
            'two_factor_required_for_all_users' => ['nullable', 'boolean'],
            'realtime_enabled' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::BYTE_FIELDS as $field) {
            $amountKey = $field . '_amount';
            $unitKey = $field . '_unit';

            if (!$this->has($amountKey) && !$this->has($unitKey)) {
                continue;
            }

            $normalized[$field] = $this->normalizeReadableBytes(
                $this->input($amountKey),
                $this->input($unitKey),
            );
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function values(): InstallationLimitValues
    {
        return new InstallationLimitValues(
            maxMarkdownBytes: $this->positiveInt('max_markdown_bytes'),
            maxHtmlBytes: $this->positiveInt('max_html_bytes'),
            artifactMaxBytes: $this->positiveInt('artifact_max_bytes'),
            maxWorkspaceStorageBytes: $this->positiveInt('max_workspace_storage_bytes'),
            maxPageStorageBytes: $this->positiveInt('max_page_storage_bytes'),
            maxPageVersions: $this->positiveInt('max_page_versions'),
            maxTagsPerPage: $this->positiveInt('max_tags_per_page'),
            twoFactorRequiredForSystemAdmins: $this->boolean('two_factor_required_for_system_admins', true),
            twoFactorRequiredForAllUsers: $this->boolean('two_factor_required_for_all_users', false),
            realtimeEnabled: $this->boolean('realtime_enabled', false),
        );
    }

    private function positiveInt(string $key): int
    {
        $value = $this->input($key);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        // Accept exactly what the 'integer' validation rule already accepted:
        // Laravel validates with filter_var(FILTER_VALIDATE_INT), which passes
        // sign-prefixed or space-padded forms (e.g. "+5") that ctype_digit rejects.
        // Narrowing harder here would turn a validated value into a 500 rather
        // than the 422 the rule promised.
        if (is_string($value)) {
            $integerValue = filter_var($value, FILTER_VALIDATE_INT);

            if ($integerValue !== false && $integerValue > 0) {
                return $integerValue;
            }
        }

        throw new LogicException(sprintf('Validated installation limit [%s] must be a positive integer.', $key));
    }

    private function normalizeReadableBytes(mixed $amount, mixed $unit): string
    {
        $amountString = is_scalar($amount) ? trim((string) $amount) : '';
        $unitString = is_scalar($unit) ? (string) $unit : '';

        if (!array_key_exists($unitString, self::BYTE_UNIT_MULTIPLIERS)) {
            return '';
        }

        if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $amountString)) {
            return $amountString;
        }

        $bytes = round(((float) $amountString) * self::BYTE_UNIT_MULTIPLIERS[$unitString]);

        if (!is_finite($bytes) || $bytes < 1 || $bytes > PHP_INT_MAX) {
            return '';
        }

        return (string) (int) $bytes;
    }
}
