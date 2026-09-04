<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $page_version_uid
 * @property string $manifest_derivative_uid
 * @property ArtifactDerivativeKind $manifest_derivative_kind
 * @property string $processor_profile
 * @property string $manifest_schema
 * @property string $engine_name
 * @property string $engine_version
 * @property int $package_entry_count
 * @property int $expanded_bytes
 * @property int $visible_sheet_count
 * @property int $omitted_hidden_sheet_count
 * @property int $projected_row_extent_count
 * @property int $projected_column_extent_count
 * @property int $omitted_hidden_row_count
 * @property int $omitted_hidden_column_count
 * @property int $cell_count
 * @property int $formula_count
 * @property int $uncached_formula_count
 * @property int $link_count
 * @property int $merge_count
 * @property bool $truncated
 * @property \Illuminate\Support\Carbon $processed_at
 * @property PageVersion $pageVersion
 * @property PageVersionDerivative $manifestDerivative
 */
final class XlsxVersionFact extends Model
{
    protected $primaryKey = 'page_version_uid';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var array<string, string> */
    protected $attributes = [
        'manifest_derivative_kind' => 'xlsx_manifest',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_entry_count' => 'integer',
            'manifest_derivative_kind' => ArtifactDerivativeKind::class,
            'expanded_bytes' => 'integer',
            'visible_sheet_count' => 'integer',
            'omitted_hidden_sheet_count' => 'integer',
            'projected_row_extent_count' => 'integer',
            'projected_column_extent_count' => 'integer',
            'omitted_hidden_row_count' => 'integer',
            'omitted_hidden_column_count' => 'integer',
            'cell_count' => 'integer',
            'formula_count' => 'integer',
            'uncached_formula_count' => 'integer',
            'link_count' => 'integer',
            'merge_count' => 'integer',
            'truncated' => 'boolean',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PageVersion, $this>
     */
    public function pageVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'page_version_uid', 'uid');
    }

    /**
     * @return BelongsTo<PageVersionDerivative, $this>
     */
    public function manifestDerivative(): BelongsTo
    {
        return $this->belongsTo(PageVersionDerivative::class, 'manifest_derivative_uid', 'uid');
    }
}
