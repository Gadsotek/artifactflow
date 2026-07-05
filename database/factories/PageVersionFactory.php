<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @extends Factory<PageVersion>
 */
final class PageVersionFactory extends Factory
{
    private const string DEFAULT_CONTENT = '<!doctype html><html><body>Factory artifact</body></html>';

    protected $model = PageVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $versionUid = (string) Str::ulid();

        return [
            'uid' => $versionUid,
            'page_uid' => Page::factory(),
            'version_number' => 1,
            'content_storage_path' => fn (array $attributes): string => self::storagePath(
                pageUid: self::stringAttribute($attributes, 'page_uid'),
                versionNumber: self::intAttribute($attributes, 'version_number'),
                versionUid: self::stringAttribute($attributes, 'uid'),
                type: PageType::HtmlArtifact,
            ),
            'content_hash' => hash('sha256', self::DEFAULT_CONTENT),
            'byte_size' => strlen(self::DEFAULT_CONTENT),
            'scan_status' => PageSecurityScanStatus::Clean,
            'scan_findings' => null,
            'source' => PageVersionSource::Upload,
            'created_by_user_uid' => User::factory(),
            'extracted_text' => 'Factory artifact',
            'source_text' => self::DEFAULT_CONTENT,
        ];
    }

    public function forPage(Page $page): self
    {
        return $this->state(fn (array $attributes): array => [
            'page_uid' => $page->uid,
            'created_by_user_uid' => $page->owner_user_uid,
            'content_storage_path' => self::storagePath(
                pageUid: $page->uid,
                versionNumber: self::intAttribute($attributes, 'version_number'),
                versionUid: self::stringAttribute($attributes, 'uid'),
                type: $page->type,
            ),
        ]);
    }

    public function withContent(string $content): self
    {
        return $this->state([
            'content_hash' => hash('sha256', $content),
            'byte_size' => strlen($content),
            'extracted_text' => strip_tags($content),
            'source_text' => $content,
        ]);
    }

    private static function storagePath(
        string $pageUid,
        int $versionNumber,
        string $versionUid,
        PageType $type,
    ): string {
        $extension = $type === PageType::HtmlArtifact ? 'html' : 'md';
        $filename = $type === PageType::HtmlArtifact ? 'index' : 'source';

        return sprintf(
            'pages/%s/versions/%d-%s/%s.%s',
            $pageUid,
            $versionNumber,
            $versionUid,
            $filename,
            $extension,
        );
    }

    /**
     * @param array<mixed> $attributes
     */
    private static function stringAttribute(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Factory attribute [%s] must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $attributes
     */
    private static function intAttribute(array $attributes, string $key): int
    {
        $value = $attributes[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf('Factory attribute [%s] must be an integer.', $key));
    }
}
