<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $external_share_uid
 * @property string $credential_hash
 * @property string $kind
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ExternalShareSession extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = [
        'credential_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalShare, $this>
     */
    public function externalShare(): BelongsTo
    {
        return $this->belongsTo(ExternalShare::class, 'external_share_uid', 'uid');
    }
}
