<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ExternalSharing\ExternalShareMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $uid
 * @property string $page_uid
 * @property string $secret_hash
 * @property ExternalShareMode $mode
 * @property CarbonImmutable|null $expires_at
 * @property string $page_workspace_uid
 * @property int $page_access_revision
 * @property string $created_by_user_uid
 * @property CarbonImmutable|null $redeemed_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by_user_uid
 * @property CarbonImmutable|null $first_viewed_at
 * @property CarbonImmutable|null $last_viewed_at
 * @property int $view_session_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $creator
 * @property-read User|null $revoker
 */
final class ExternalShare extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * Share state and credential material are mutated only by explicit
     * external-sharing application handlers.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = [
        'secret_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ExternalShareMode::class,
            'expires_at' => 'immutable_datetime',
            'page_access_revision' => 'integer',
            'redeemed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'first_viewed_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
            'view_session_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_uid', 'uid');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_uid', 'uid');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_uid', 'uid');
    }

    /**
     * @return HasMany<ExternalShareSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ExternalShareSession::class, 'external_share_uid', 'uid');
    }
}
