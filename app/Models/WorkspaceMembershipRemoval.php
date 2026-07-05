<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $workspace_uid
 * @property string $user_uid
 * @property CarbonImmutable $removed_at
 */
final class WorkspaceMembershipRemoval extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_uid',
        'user_uid',
        'removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'removed_at' => 'immutable_datetime',
        ];
    }
}
