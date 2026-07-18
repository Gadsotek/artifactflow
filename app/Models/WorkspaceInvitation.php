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
 * @property string $token_hash
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
     * The plaintext emailed link secret, held only in memory for the single
     * response that mints it (creation or reissue) or the request that route-binds
     * it back. Never persisted -- the database stores only its SHA-256 in
     * `token_hash` -- and never serialized, since it is a declared property rather
     * than an Eloquent attribute.
     */
    public ?string $plainToken = null;

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
     * The link secret's hash is never rendered; keep it out of array/JSON
     * serialization so it cannot leak through an accidental model dump.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
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

    /**
     * Mint a fresh emailed link secret: persist only its SHA-256 in `token_hash`
     * and keep the plaintext on the model for the one response that will email it.
     * Called at creation and on every reissue that must invalidate a link already
     * sent. Returns the plaintext so the caller can build the emailed URL.
     */
    public function issueFreshToken(): string
    {
        $plainToken = self::freshToken();
        $this->token_hash = hash('sha256', $plainToken);
        $this->plainToken = $plainToken;

        return $plainToken;
    }

    /**
     * Resolve the public join route's `{invitation:token}` binding against the
     * hashed column: the URL carries the plaintext secret, the database holds only
     * its hash. The matched invitation keeps the presented plaintext on `plainToken`
     * so the same request can rebuild the join/register URLs. All other bindings
     * (the UID-keyed management routes) fall through to the default resolution.
     *
     * @param  int|string  $value
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== 'token') {
            return parent::resolveRouteBinding($value, $field);
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $invitation = $this->newQuery()->where('token_hash', hash('sha256', $value))->first();

        if ($invitation instanceof self) {
            $invitation->plainToken = $value;
        }

        return $invitation;
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
        // The emailed link's bearer secret. Minted at creation and reissued on
        // reactivation (see InviteUserToWorkspace) so that reviving a revoked or
        // expired invitation invalidates any link already in an inbox. Only its
        // hash is stored; the plaintext is kept on the model instance for the
        // creating response. Never mass-assignable.
        static::creating(function (WorkspaceInvitation $invitation): void {
            $tokenHash = $invitation->getAttribute('token_hash');

            if (!is_string($tokenHash) || $tokenHash === '') {
                $invitation->issueFreshToken();
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
