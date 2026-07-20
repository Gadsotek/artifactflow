<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $uid
 * @property string $principal_user_uid
 * @property string $name
 * @property string $token_hash
 * @property list<string> $scopes
 * @property list<string>|null $workspace_uids
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property User $principal
 */
final class McpAccessToken extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * Token credentials, authority, and workspace scope are issued only by
     * the dedicated token service.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The SHA-256 token hash is the credential's verification material. It is
     * never needed by any view or API payload, so keep it out of array/JSON
     * serialization to avoid leaking it through an accidental model dump.
     *
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
            'scopes' => 'array',
            'workspace_uids' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->normalizedScopes(), true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Null means unrestricted within the principal's current permissions.
     *
     * @return list<string>|null
     */
    public function workspaceUids(): ?array
    {
        if ($this->workspace_uids === null) {
            return null;
        }

        $workspaceUids = [];

        foreach ($this->workspace_uids as $workspaceUid) {
            if ($workspaceUid !== '') {
                $workspaceUids[] = $workspaceUid;
            }
        }

        return array_values(array_unique($workspaceUids));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_user_uid', 'uid');
    }

    /**
     * @return list<string>
     */
    private function normalizedScopes(): array
    {
        $normalized = [];

        foreach ($this->scopes as $scope) {
            if ($scope !== '') {
                $normalized[] = $scope;
            }
        }

        return array_values(array_unique($normalized));
    }
}
