<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\Provenance\VersionOperation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $uid
 * @property string $page_uid
 * @property string $page_version_uid
 * @property string $content_origin_version_uid
 * @property int $version_number
 * @property string $content_hash
 * @property VersionOperation $operation
 * @property PageVersionSource $ingest_method
 * @property string $actor_user_uid
 * @property string|null $mcp_access_token_uid
 * @property string|null $mcp_transport_session_id
 * @property string|null $mcp_client_reported_name
 * @property string|null $mcp_client_reported_version
 * @property bool $provenance_supplied_at_ingest
 * @property string|null $derived_from_version_uid
 * @property string|null $content_equivalent_to_version_uid
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class PageVersionIngest extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'operation' => VersionOperation::class,
            'ingest_method' => PageVersionSource::class,
            'provenance_supplied_at_ingest' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_uid', 'uid');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_uid', 'uid');
    }

    /** @return HasMany<ProducerAssertion, $this> */
    public function assertions(): HasMany
    {
        return $this->hasMany(ProducerAssertion::class, 'page_version_ingest_uid', 'uid');
    }
}
