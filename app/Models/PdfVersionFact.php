<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\PdfExtractionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $page_version_uid
 * @property int $page_count
 * @property string $pdf_version
 * @property PdfExtractionState $extraction_state
 * @property string $processor_profile
 * @property PageVersion $pageVersion
 */
final class PdfVersionFact extends Model
{
    protected $primaryKey = 'page_version_uid';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * PDF facts are written only by the PDF ingestion boundary after a
     * processor response has been authenticated and validated.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
            'extraction_state' => PdfExtractionState::class,
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
