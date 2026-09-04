<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PdfExtractionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $page_version_uid
 * @property string $preview_derivative_uid
 * @property ArtifactDerivativeKind $preview_derivative_kind
 * @property string $docx_processor_profile
 * @property string $pdf_processor_profile
 * @property string $engine_name
 * @property string $engine_version
 * @property int $package_entry_count
 * @property int $expanded_bytes
 * @property int $relationship_count
 * @property int $media_count
 * @property int $external_hyperlink_count
 * @property int $page_count
 * @property string $pdf_version
 * @property PdfExtractionState $extraction_state
 * @property \Illuminate\Support\Carbon $processed_at
 * @property PageVersionDerivative $previewDerivative
 */
final class DocxVersionFact extends Model
{
    protected $primaryKey = 'page_version_uid';
    public $incrementing = false;
    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var array<string, string> */
    protected $attributes = [
        'preview_derivative_kind' => 'docx_preview_pdf',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'package_entry_count' => 'integer',
            'preview_derivative_kind' => ArtifactDerivativeKind::class,
            'expanded_bytes' => 'integer',
            'relationship_count' => 'integer',
            'media_count' => 'integer',
            'external_hyperlink_count' => 'integer',
            'page_count' => 'integer',
            'extraction_state' => PdfExtractionState::class,
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PageVersion, $this> */
    public function pageVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'page_version_uid', 'uid');
    }

    /** @return BelongsTo<PageVersionDerivative, $this> */
    public function previewDerivative(): BelongsTo
    {
        return $this->belongsTo(PageVersionDerivative::class, 'preview_derivative_uid', 'uid');
    }
}
