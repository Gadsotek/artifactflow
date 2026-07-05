<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\WorkspaceRole;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $uid
 * @property string $workspace_uid
 * @property string $invited_email
 * @property string $token
 * @property WorkspaceRole $role
 * @property string $invited_by_user_uid
 * @property string|null $accepted_by_user_uid
 * @property \DateTimeInterface|null $accepted_at
 * @property \DateTimeInterface|null $revoked_at
 * @property \DateTimeInterface|null $expires_at
 */
final class WorkspaceInvitation extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_uid',
        'invited_email',
        'invited_by_user_uid',
        'accepted_by_user_uid',
        'accepted_at',
        'revoked_at',
        'expires_at',
    ];

    /**
     * The link secret is never rendered; keep it out of array/JSON serialization
     * so it cannot leak through an accidental model dump.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Length of the emailed link's bearer secret.
     */
    public const int TOKEN_LENGTH = 48;

    /**
     * A fresh random link secret. Reused by creation and by reactivation, which
     * must issue a NEW secret so a revoked or expired link cannot resurrect.
     */
    public static function freshToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at instanceof DateTimeInterface
            && $this->expires_at->getTimestamp() >= now()->getTimestamp();
    }

    /**
     * @param Builder<WorkspaceInvitation> $query
     *
     * @return Builder<WorkspaceInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now());
    }

    protected static function booted(): void
    {
        // The emailed link's bearer secret. Generated at creation and rotated on
        // reactivation (see InviteUserToWorkspace) so that reviving a revoked or
        // expired invitation invalidates any link already in an inbox. Never
        // mass-assignable.
        static::creating(function (WorkspaceInvitation $invitation): void {
            $token = $invitation->getAttribute('token');

            if (!is_string($token) || $token === '') {
                $invitation->token = self::freshToken();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
