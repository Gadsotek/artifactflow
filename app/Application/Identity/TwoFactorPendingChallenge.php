<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class TwoFactorPendingChallenge
{
    public const string SESSION_KEY = 'auth.two_factor_challenge';

    private const string CACHE_PREFIX = 'two-factor-challenge:';

    public function create(Request $request, User $user, bool $remember): void
    {
        $nonce = Str::random(64);
        $createdAt = now()->getTimestamp();

        Cache::put($this->cacheKey($nonce), $this->cacheValue($user), $this->timeoutSeconds());

        $request->session()->put(self::SESSION_KEY, [
            'user_uid' => $user->uid,
            'created_at' => $createdAt,
            'remember' => $remember,
            'nonce' => $nonce,
            'attempts' => 0,
            'auth_revision' => $user->auth_revision,
        ]);
        $request->session()->regenerate();
    }

    public function user(Request $request): ?User
    {
        $marker = $this->marker($request);
        if ($marker === null) {
            $this->clear($request);

            return null;
        }

        $uid = $marker['user_uid'];
        $nonce = $marker['nonce'];
        $cachedValue = Cache::get($this->cacheKey($nonce));

        if ($cachedValue !== $this->cacheValue($uid, $marker['auth_revision'])) {
            $this->clear($request);

            return null;
        }

        if ((now()->getTimestamp() - $marker['created_at']) > $this->timeoutSeconds()) {
            $this->clear($request);

            return null;
        }

        $user = User::query()->where('uid', $uid)->first();
        if (
            !$user instanceof User
            || !$user->hasEnabledTwoFactor()
            || $user->auth_revision !== $marker['auth_revision']
        ) {
            $this->clear($request);

            return null;
        }

        return $user;
    }

    public function remember(Request $request): bool
    {
        $marker = $this->marker($request);

        return $marker !== null && $marker['remember'];
    }

    public function authRevision(Request $request): ?int
    {
        return $this->marker($request)['auth_revision'] ?? null;
    }

    public function recordFailure(Request $request): void
    {
        $marker = $this->marker($request);
        if ($marker === null) {
            $this->clear($request);

            return;
        }

        $marker['attempts']++;
        if ($marker['attempts'] >= 5) {
            $this->clear($request);

            return;
        }

        $request->session()->put(self::SESSION_KEY, $marker);
    }

    public function clear(Request $request): void
    {
        $marker = $this->marker($request);
        if ($marker !== null) {
            Cache::forget($this->cacheKey($marker['nonce']));
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array{user_uid: string, created_at: int, remember: bool, nonce: string, attempts: int, auth_revision: int}|null
     */
    private function marker(Request $request): ?array
    {
        $marker = $request->session()->get(self::SESSION_KEY);

        if (!is_array($marker)) {
            return null;
        }

        $uid = $marker['user_uid'] ?? null;
        $createdAt = $marker['created_at'] ?? null;
        $remember = $marker['remember'] ?? null;
        $nonce = $marker['nonce'] ?? null;
        $attempts = $marker['attempts'] ?? 0;
        $authRevision = $marker['auth_revision'] ?? null;

        if (
            !is_string($uid)
            || $uid === ''
            || (!is_int($createdAt) && !(is_string($createdAt) && ctype_digit($createdAt)))
            || !is_bool($remember)
            || !is_string($nonce)
            || $nonce === ''
            || (!is_int($attempts) && !(is_string($attempts) && ctype_digit((string) $attempts)))
            || (!is_int($authRevision) && !(is_string($authRevision) && ctype_digit($authRevision)))
        ) {
            return null;
        }

        return [
            'user_uid' => $uid,
            'created_at' => (int) $createdAt,
            'remember' => $remember,
            'nonce' => $nonce,
            'attempts' => (int) $attempts,
            'auth_revision' => (int) $authRevision,
        ];
    }

    private function timeoutSeconds(): int
    {
        $value = config('auth.two_factor_challenge_timeout', 300);
        $timeout = is_int($value) || is_string($value) ? (int) $value : 300;

        return max(60, $timeout);
    }

    private function cacheKey(string $nonce): string
    {
        return self::CACHE_PREFIX . hash('sha256', $nonce);
    }

    private function cacheValue(User|string $user, ?int $authRevision = null): string
    {
        if ($user instanceof User) {
            return $this->cacheValue($user->uid, $user->auth_revision);
        }

        if ($authRevision === null) {
            return '';
        }

        return hash('sha256', $user . ':' . $authRevision);
    }
}
