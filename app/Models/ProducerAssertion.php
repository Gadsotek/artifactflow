<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Provenance\ProducerKind;
use App\Domain\Provenance\ProvenanceEvidenceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $uid
 * @property string $page_version_ingest_uid
 * @property ProducerKind $producer_kind
 * @property string|null $producer_name
 * @property string|null $producer_version
 * @property string|null $provider_key
 * @property string|null $model_id
 * @property string|null $model_label
 * @property string|null $model_version
 * @property \Carbon\CarbonImmutable|null $generated_at
 * @property ProvenanceEvidenceType $evidence_type
 * @property string $asserted_by_user_uid
 * @property string|null $supersedes_assertion_uid
 * @property \Carbon\CarbonImmutable $asserted_at
 */
final class ProducerAssertion extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'producer_kind' => ProducerKind::class,
            'generated_at' => 'immutable_datetime',
            'evidence_type' => ProvenanceEvidenceType::class,
            'asserted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PageVersionIngest, $this> */
    public function ingest(): BelongsTo
    {
        return $this->belongsTo(PageVersionIngest::class, 'page_version_ingest_uid', 'uid');
    }

    /** @return HasMany<ExternalOriginReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(ExternalOriginReference::class, 'producer_assertion_uid', 'uid');
    }

    /** @return HasOne<ProducerAssertion, $this> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_assertion_uid', 'uid');
    }
}
