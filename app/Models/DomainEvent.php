<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_uid
 * @property array<string, bool|int|string|null> $payload
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property \Carbon\CarbonImmutable|null $dispatched_at
 * @property int $dispatch_attempts
 * @property \Carbon\CarbonImmutable|null $failed_at
 * @property string|null $last_error
 */
final class DomainEvent extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_uid',
        'payload',
        'occurred_at',
        'dispatched_at',
        'dispatch_attempts',
        'failed_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'dispatch_attempts' => 'integer',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
