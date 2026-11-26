<?php

declare(strict_types=1);

namespace App\Models;

use App\Application\PassageRetrieval\SourcePassage;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-import-type MarkdownLocator from SourcePassage
 * @phpstan-import-type HtmlLocator from SourcePassage
 * @property string $excerpt
 * @property list<string> $heading_path
 * @property MarkdownLocator|HtmlLocator $source_locator
 */
final class IndexedPassage extends Model
{
    use HasUlids;

    public $timestamps = false;
    /** @var list<string> */
    protected $guarded = ['*'];
    protected $table = 'passages';
    protected $primaryKey = 'uid';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['heading_path' => 'array', 'source_locator' => 'array'];
    }
}
