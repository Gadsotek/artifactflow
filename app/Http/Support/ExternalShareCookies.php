<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Application\ExternalSharing\IssuedExternalShareSession;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class ExternalShareCookies
{
    public const string PENDING_COOKIE = 'artifactflow_external_pending';

    public const string VIEW_COOKIE = 'artifactflow_external_view';

    public function credential(Request $request, ExternalShareSessionKind $kind): ?string
    {
        $value = $request->cookies->get($this->name($kind));

        return is_string($value) ? $value : null;
    }

    public function attach(
        Response $response,
        Request $request,
        string $externalShareUid,
        IssuedExternalShareSession $issued,
    ): void {
        $kind = ExternalShareSessionKind::from($issued->session->kind);
        $expiresAt = $kind === ExternalShareSessionKind::View
            ? 0
            : $issued->session->expires_at;

        if ($expiresAt === null) {
            throw new LogicException('Pending external-share cookies require an expiry.');
        }

        $response->headers->setCookie(Cookie::create(
            name: $this->name($kind),
            value: $issued->credential(),
            expire: $expiresAt,
            path: $this->path($externalShareUid),
            secure: $this->isSecure($request),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        ));
    }

    public function clearPending(Response $response, Request $request, string $externalShareUid): void
    {
        $response->headers->setCookie(Cookie::create(
            name: self::PENDING_COOKIE,
            value: '',
            expire: CarbonImmutable::now()->subYear(),
            path: $this->path($externalShareUid),
            secure: $this->isSecure($request),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        ));
    }

    private function name(ExternalShareSessionKind $kind): string
    {
        return match ($kind) {
            ExternalShareSessionKind::PendingOpen => self::PENDING_COOKIE,
            ExternalShareSessionKind::View => self::VIEW_COOKIE,
        };
    }

    private function path(string $externalShareUid): string
    {
        return '/external-shares/' . rawurlencode($externalShareUid);
    }

    private function isSecure(Request $request): bool
    {
        return $request->isSecure() || (bool) config('session.secure');
    }
}
