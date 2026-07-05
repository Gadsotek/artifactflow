<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $user_uid
 * @property string $token_hash
 * @property string $label
 * @property string $user_agent_summary
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property User $user
 */
final class TrustedDevice extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uid', 'uid');
    }
}
