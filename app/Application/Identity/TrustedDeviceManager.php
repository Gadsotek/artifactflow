<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Domain\Events\DomainEventType;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class TrustedDeviceManager
{
    public const string COOKIE_NAME = 'artifactflow_trusted_device';

    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function findValidDevice(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE_NAME);
        if (!is_string($token) || $token === '') {
            return null;
        }

        $device = null;
        foreach ($this->candidateTokens($token) as $candidateToken) {
            $device = TrustedDevice::query()
                ->where('user_uid', $user->uid)
                ->where('token_hash', hash('sha256', $candidateToken))
                ->where('expires_at', '>', now())
                ->first();

            if ($device instanceof TrustedDevice) {
                break;
            }
        }

        if (!$device instanceof TrustedDevice) {
            return null;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return $device;
    }

    /**
     * @return array{token: string, device: TrustedDevice}|null
     */
    public function remember(User $user, Request $request, int $expectedAuthRevision): ?array
    {
        $token = bin2hex(random_bytes(40));
        $userAgentSummary = $this->summarizeUserAgent($request->userAgent());

        return DB::transaction(function () use (
            $expectedAuthRevision,
            $token,
            $user,
            $userAgentSummary,
        ): ?array {
            $lockedUser = User::query()
                ->where('uid', $user->uid)
                ->lockForUpdate()
                ->sole();

            if (
                $lockedUser->auth_revision !== $expectedAuthRevision
                || !$lockedUser->hasEnabledTwoFactor()
            ) {
                return null;
            }

            $device = TrustedDevice::query()->forceCreate([
                'user_uid' => $lockedUser->uid,
                'token_hash' => hash('sha256', $token),
                'label' => $userAgentSummary,
                'user_agent_summary' => $userAgentSummary,
                'expires_at' => now()->addDays($this->trustedDeviceDays()),
                'last_used_at' => now(),
            ]);

            $event = $this->events->record(
                eventType: DomainEventType::UserTwoFactorTrustedDeviceAdded,
                aggregateType: 'user',
                aggregateUid: $lockedUser->uid,
                payload: [
                    'user_uid' => $lockedUser->uid,
                    'trusted_device_uid' => $device->uid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $lockedUser->uid,
                auditableType: 'trusted_device',
                auditableUid: $device->uid,
                action: DomainEventType::UserTwoFactorTrustedDeviceAdded,
                summary: 'Two-factor trusted device added.',
                metadata: [
                    'trusted_device_uid' => $device->uid,
                ],
            );

            return [
                'token' => $token,
                'device' => $device,
            ];
        });
    }

    public function revoke(User $actor, TrustedDevice $trustedDevice): void
    {
        if ($trustedDevice->user_uid !== $actor->uid) {
            // 404, not 403: another user's device UID must be indistinguishable
            // from a missing one (same pattern as MCP token self-service revoke).
            abort(404);
        }

        $trustedDeviceUid = $trustedDevice->uid;

        DB::transaction(function () use ($actor, $trustedDeviceUid): void {
            $user = User::query()
                ->where('uid', $actor->uid)
                ->lockForUpdate()
                ->sole();
            $lockedTrustedDevice = TrustedDevice::query()
                ->where('uid', $trustedDeviceUid)
                ->where('user_uid', $user->uid)
                ->lockForUpdate()
                ->first();

            if (!$lockedTrustedDevice instanceof TrustedDevice) {
                abort(404);
            }

            $lockedTrustedDevice->delete();

            $event = $this->events->record(
                eventType: DomainEventType::UserTwoFactorTrustedDeviceRevoked,
                aggregateType: 'user',
                aggregateUid: $user->uid,
                payload: [
                    'user_uid' => $user->uid,
                    'trusted_device_uid' => $trustedDeviceUid,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $user->uid,
                auditableType: 'trusted_device',
                auditableUid: $trustedDeviceUid,
                action: DomainEventType::UserTwoFactorTrustedDeviceRevoked,
                summary: 'Two-factor trusted device revoked.',
                metadata: [
                    'trusted_device_uid' => $trustedDeviceUid,
                ],
            );
        });
    }

    /**
     * @throws ValidationException
     */
    public function revokeAll(User $actor, int $expectedAuthRevision): TrustedDeviceRevocation
    {
        return DB::transaction(function () use ($actor, $expectedAuthRevision): TrustedDeviceRevocation {
            $user = User::query()
                ->where('uid', $actor->uid)
                ->lockForUpdate()
                ->sole();

            if ($user->auth_revision !== $expectedAuthRevision) {
                throw ValidationException::withMessages([
                    'trusted_devices' => 'Your authentication state changed. Sign in again before revoking trusted devices.',
                ]);
            }

            $count = DB::table('trusted_devices')->where('user_uid', $user->uid)->delete();
            $committedAuthRevision = $expectedAuthRevision + 1;
            $user->forceFill(['auth_revision' => $committedAuthRevision])->save();

            $event = $this->events->record(
                eventType: DomainEventType::UserTwoFactorTrustedDevicesRevokedAll,
                aggregateType: 'user',
                aggregateUid: $user->uid,
                payload: [
                    'user_uid' => $user->uid,
                    'trusted_devices_revoked' => $count,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $user->uid,
                auditableType: 'user',
                auditableUid: $user->uid,
                action: DomainEventType::UserTwoFactorTrustedDevicesRevokedAll,
                summary: 'All two-factor trusted devices revoked.',
                metadata: [
                    'trusted_devices_revoked' => $count,
                ],
            );

            return new TrustedDeviceRevocation(
                trustedDevicesRevoked: $count,
                authRevision: $committedAuthRevision,
                user: $user,
            );
        });
    }

    private function trustedDeviceDays(): int
    {
        $value = config('auth.two_factor_trusted_device_days', 30);
        $days = is_int($value) || is_string($value) ? (int) $value : 30;

        return max(1, $days);
    }

    /**
     * @return list<string>
     */
    private function candidateTokens(string $token): array
    {
        $tokens = [$token];
        $decodedToken = rawurldecode($token);
        if ($decodedToken !== '' && $decodedToken !== $token) {
            $tokens[] = $decodedToken;
        }

        foreach ($tokens as $candidateToken) {
            try {
                $decrypted = Crypt::decryptString($candidateToken);
                foreach ([$decrypted, CookieValuePrefix::remove($decrypted)] as $decryptedCandidate) {
                    if ($decryptedCandidate !== '' && !in_array($decryptedCandidate, $tokens, true)) {
                        $tokens[] = $decryptedCandidate;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $tokens;
    }

    private function summarizeUserAgent(?string $userAgent): string
    {
        $summary = is_string($userAgent) && trim($userAgent) !== '' ? trim($userAgent) : 'Unknown device';
        $summary = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $summary) ?? 'Unknown device';
        $summary = trim($summary);

        return mb_substr($summary === '' ? 'Unknown device' : $summary, 0, 120);
    }
}
