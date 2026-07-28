<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Provenance\ExternalReferenceKind;
use App\Domain\Provenance\ExternalReferenceRetentionClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $producer_assertion_uid
 * @property ExternalReferenceKind $reference_kind
 * @property string|null $external_ref
 * @property string|null $url
 * @property ExternalReferenceRetentionClass $retention_class
 * @property \Carbon\CarbonImmutable|null $redacted_at
 * @property string|null $redacted_by_user_uid
 */
final class ExternalOriginReference extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reference_kind' => ExternalReferenceKind::class,
            'retention_class' => ExternalReferenceRetentionClass::class,
            'redacted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProducerAssertion, $this> */
    public function assertion(): BelongsTo
    {
        return $this->belongsTo(ProducerAssertion::class, 'producer_assertion_uid', 'uid');
    }
}
