<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\WorkspaceRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $workspace_uid
 * @property string $user_uid
 * @property WorkspaceRole $role
 */
final class WorkspaceMembership extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_uid',
        'user_uid',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
