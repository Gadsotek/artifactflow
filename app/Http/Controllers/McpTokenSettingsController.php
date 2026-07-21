<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\VerifyTwoFactorCode;
use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceNavigationItem;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpAccessTokenRevoker;
use App\Domain\DomainRuleViolation;
use App\Domain\Mcp\StaleMcpAuthenticationRevision;
use App\Http\Requests\Mcp\StoreMcpAccessTokenRequest;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final readonly class McpTokenSettingsController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private McpAccessTokenIssuer $issuer,
        private McpAccessTokenRevoker $revoker,
        private VerifyTwoFactorCode $twoFactor,
        private WorkspaceContext $workspaceContext,
    ) {
    }

    public function index(Request $request): View
    {
        return $this->view($this->authenticatedUser($request));
    }

    public function store(StoreMcpAccessTokenRequest $request): View
    {
        $user = $this->authenticatedUser($request);
        $expectedAuthRevision = $user->auth_revision;
        $scopes = $request->scopes();
        $expiresInDays = $request->expiresInDays();

        if (
            McpAccessTokenIssuer::includesWriteScope($scopes)
            && $expiresInDays > McpAccessTokenIssuer::MAX_WRITE_SCOPE_TTL_DAYS
        ) {
            throw ValidationException::withMessages([
                'expires_in_days' => 'Write-capable tokens must expire within '
                    . McpAccessTokenIssuer::MAX_WRITE_SCOPE_TTL_DAYS . ' days.',
            ]);
        }

        if (!$user->hasEnabledTwoFactor()) {
            throw ValidationException::withMessages([
                'code' => 'Enable two-factor authentication before creating MCP tokens.',
            ]);
        }

        $password = $request->password();
        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The current password is incorrect.',
            ]);
        }

        $code = $request->twoFactorCode();
        if (!$this->twoFactor->verifyTotpAndAdvance($user, $code, $expectedAuthRevision)) {
            throw ValidationException::withMessages([
                'code' => 'The authentication code is invalid or has already been used.',
            ]);
        }

        // An explicit "all workspaces" choice mints the unrestricted (present and
        // future) scope; an empty individual selection is still a deny, never all.
        // Write scopes are permitted at this breadth: every create/update still runs
        // through the same per-workspace policies as a human (the token is capped at
        // Editor authority), so it can only write where the account already may, and
        // the shorter write-token TTL enforced above keeps the exposure window tight.
        $workspaceUids = $request->allWorkspaces()
            ? null
            : $this->validatedWorkspaceUids($user, $request->workspaceUids());

        try {
            $issued = $this->issuer->issue(
                principal: $user,
                name: $request->tokenName(),
                scopes: $scopes,
                expiresAt: Carbon::now()->addDays($expiresInDays),
                actor: $user,
                channel: 'self-service',
                workspaceUids: $workspaceUids,
                expectedAuthRevision: $expectedAuthRevision,
            );
        } catch (StaleMcpAuthenticationRevision) {
            throw ValidationException::withMessages([
                'password' => 'Your authentication changed while the token was being created. '
                    . 'Confirm your password and authentication code again.',
            ]);
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'code' => $exception->getMessage(),
            ]);
        }

        return $this->view($user, $issued->plainTextToken);
    }

    public function destroy(Request $request, McpAccessToken $mcpAccessToken): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        if ($mcpAccessToken->principal_user_uid !== $user->uid) {
            abort(404);
        }

        $this->revoker->revoke($mcpAccessToken, $user, 'self-service');

        return redirect()
            ->route('settings.mcp-tokens.index')
            ->with('status', 'MCP token revoked.');
    }

    private function view(User $user, ?string $plainTextToken = null): View
    {
        return view('settings.mcp-tokens.index', [
            'user' => $user,
            'tokens' => McpAccessToken::query()
                ->where('principal_user_uid', $user->uid)
                ->orderByDesc('created_at')
                ->get(),
            'availableScopes' => McpAccessTokenIssuer::allowedScopes(),
            'defaultScopes' => [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_READ],
            'plainTextToken' => $plainTextToken,
            'workspaceItems' => $this->workspaceContext->itemsFor($user),
        ]);
    }

    /**
     * @param list<string> $workspaceUids
     *
     * @return list<string>
     */
    private function validatedWorkspaceUids(User $user, array $workspaceUids): array
    {
        $workspaceUids = array_values(array_unique($workspaceUids));

        // Least privilege: when the caller has not chosen "all workspaces", the
        // token must name at least one workspace. An empty selection is a deny,
        // never a silent fall-through to the unrestricted scope.
        if ($workspaceUids === []) {
            throw ValidationException::withMessages([
                'workspace_uids' => 'Select at least one workspace for this token.',
            ]);
        }

        $allowedWorkspaceUids = array_map(
            static fn (WorkspaceNavigationItem $item): string => $item->uid,
            $this->workspaceContext->itemsFor($user),
        );
        $invalidWorkspaceUids = array_diff($workspaceUids, $allowedWorkspaceUids);

        if ($invalidWorkspaceUids !== []) {
            throw ValidationException::withMessages([
                'workspace_uids' => 'Select only workspaces you can access.',
            ]);
        }

        return $workspaceUids;
    }
}
