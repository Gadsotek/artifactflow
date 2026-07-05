<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageVersionSource;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $page_uid
 * @property int $version_number
 * @property string $content_storage_path
 * @property string $content_hash
 * @property int $byte_size
 * @property PageSecurityScanStatus $scan_status
 * @property list<array{severity: string, code: string, message: string}>|null $scan_findings
 * @property PageVersionSource $source
 * @property string $created_by_user_uid
 * @property string|null $extracted_text
 * @property string|null $source_text
 * @property User $creator
 */
final class PageVersion extends Model
{
    /** @use HasFactory<\Database\Factories\PageVersionFactory> */
    use HasFactory;
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'byte_size' => 'integer',
            'scan_status' => PageSecurityScanStatus::class,
            'scan_findings' => 'array',
            'source' => PageVersionSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_uid', 'uid');
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_uid', 'uid');
    }
}
