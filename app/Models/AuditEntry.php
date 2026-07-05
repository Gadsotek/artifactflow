<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $event_uid
 * @property string|null $aggregate_type
 * @property string|null $aggregate_uid
 * @property string|null $actor_user_uid
 * @property string $auditable_type
 * @property string $auditable_uid
 * @property string $action
 * @property string $summary
 * @property array<string, bool|int|string|null> $metadata
 * @property CarbonImmutable $occurred_at
 * @property User|null $actor
 * @property DomainEvent $event
 */
final class AuditEntry extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_uid',
        'aggregate_type',
        'aggregate_uid',
        'actor_user_uid',
        'auditable_type',
        'auditable_uid',
        'action',
        'summary',
        'metadata',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_uid', 'uid');
    }

    /**
     * @return BelongsTo<DomainEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class, 'event_uid', 'uid');
    }
}
