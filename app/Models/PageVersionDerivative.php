<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $page_version_uid
 * @property ArtifactDerivativeKind $kind
 * @property string $storage_path
 * @property string $content_hash
 * @property int $byte_size
 * @property PageVersion $pageVersion
 */
final class PageVersionDerivative extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ArtifactDerivativeKind::class,
            'byte_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PageVersion, $this>
     */
    public function pageVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'page_version_uid', 'uid');
    }
}
