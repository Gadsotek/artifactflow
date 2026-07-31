<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\ExternalSharing\ExternalShareUrl;
use App\Application\ExternalSharing\RevokeExternalShare;
use App\Application\ExternalSharing\RevokeExternalShareCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Requests\PageCatalog\CreateExternalShareRequest;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExternalShareManagementController
{
    use ResolvesAuthenticatedUser;

    public function store(
        CreateExternalShareRequest $request,
        Page $page,
        CreateExternalShare $createExternalShare,
        ExternalShareUrl $urls,
    ): JsonResponse {
        $actor = $this->authenticatedUser($request);

        try {
            $issued = $createExternalShare->handle($actor, new CreateExternalShareCommand(
                pageUid: $page->uid,
                mode: $request->mode(),
                expiresAt: $request->expiresAt(),
            ));
        } catch (DomainRuleViolation $exception) {
            $field = str_contains(strtolower($exception->getMessage()), 'expir')
                ? 'expires_at'
                : 'external_share';

            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
            ]);
        }

        return response()
            ->json([
                'url' => $urls->forIssuedShare($issued),
                'share' => [
                    'uid' => $issued->share->uid,
                    'mode' => $issued->share->mode->value,
                    'status' => 'active',
                    'expires_at' => $issued->share->expires_at?->toISOString(),
                    'created_at' => $issued->share->created_at->toISOString(),
                    'created_by' => $actor->name,
                    'revoke_url' => route('pages.external-shares.destroy', [
                        'page' => $page,
                        'externalShareUid' => $issued->share->uid,
                    ]),
                ],
            ], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(
        Request $request,
        Page $page,
        string $externalShareUid,
        RevokeExternalShare $revokeExternalShare,
    ): RedirectResponse {
        $actor = $this->authenticatedUser($request);
        $revoked = $revokeExternalShare->handle($actor, new RevokeExternalShareCommand(
            pageUid: $page->uid,
            externalShareUid: $externalShareUid,
        ));

        return redirect()
            ->route('pages.show', $page)
            ->with('status', $revoked ? 'External share revoked.' : 'External share is no longer active.');
    }
}
