<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\ThemePreference;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $uid
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_system_admin
 * @property bool $is_service_account
 * @property ThemePreference $theme_preference
 * @property string|null $two_factor_secret
 * @property \Illuminate\Support\Carbon|null $two_factor_secret_created_at
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property list<string>|null $two_factor_recovery_codes
 * @property int|null $two_factor_last_used_timestep
 * @property bool $two_factor_required
 * @property int $auth_revision
 * @property \Illuminate\Support\Carbon|null $password_reset_notice_pending_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasUlids;
    use Notifiable;

    protected $primaryKey = 'uid';

    /**
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'is_system_admin' => false,
        'theme_preference' => 'system',
        'auth_revision' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'theme_preference',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_secret_created_at',
        'two_factor_recovery_codes',
        'auth_revision',
        'password_reset_notice_pending_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_system_admin' => 'boolean',
            'is_service_account' => 'boolean',
            'password' => 'hashed',
            'theme_preference' => ThemePreference::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_secret_created_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
            'two_factor_last_used_timestep' => 'integer',
            'two_factor_required' => 'boolean',
            'auth_revision' => 'integer',
            'password_reset_notice_pending_at' => 'datetime',
        ];
    }

    public function hasEnabledTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class, 'user_uid', 'uid');
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
