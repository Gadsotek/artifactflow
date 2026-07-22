<?php

declare(strict_types=1);

use App\Http\Controllers\ArtifactDraftPreviewCapabilityController;
use App\Http\Controllers\ArtifactDraftPreviewController;
use App\Http\Controllers\ArtifactHistoryPreviewUrlController;
use App\Http\Controllers\ArtifactPreviewController;
use App\Http\Controllers\ArtifactPreviewUrlController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoContentController;
use App\Http\Controllers\InstallationSettingsController;
use App\Http\Controllers\MarkdownPreviewController;
use App\Http\Controllers\McpTokenSettingsController;
use App\Http\Controllers\PageAccessGrantController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PageLifecycleController;
use App\Http\Controllers\PageMetadataController;
use App\Http\Controllers\PagePresenceController;
use App\Http\Controllers\PageVersionController;
use App\Http\Controllers\PageVersionInspectionController;
use App\Http\Controllers\PageWorkspaceController;
use App\Http\Controllers\PasswordConfirmationController;
use App\Http\Controllers\SwitchWorkspaceController;
use App\Http\Controllers\SystemAdminPasswordConfirmationController;
use App\Http\Controllers\SystemUserController;
use App\Http\Controllers\ThemePreferenceController;
use App\Http\Controllers\TwoFactorSettingsController;
use App\Http\Controllers\WorkspaceCollaboratorController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Controllers\WorkspaceInvitationJoinController;
use App\Http\Controllers\WorkspaceMembershipController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Middleware\EnforceCurrentAuthenticationRevision;
use App\Http\Middleware\EnforceTwoFactorEnrollment;
use App\Http\Middleware\RejectArtifactHostRuntime;
use App\Http\Middleware\RequireArtifactHostRuntime;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Http\Middleware\RequireRecentSystemAdminPasswordConfirmation;
use Illuminate\Support\Facades\Route;

Route::get('/artifact-previews/{pageUid}/versions/{versionUid}', ArtifactPreviewController::class)
    ->name('artifact-previews.show')
    ->middleware([RequireArtifactHostRuntime::class, 'throttle:artifact-previews'])
    ->withoutMiddleware([
        Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\Cookie\Middleware\EncryptCookies::class,
        Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        Illuminate\Session\Middleware\StartSession::class,
        Illuminate\View\Middleware\ShareErrorsFromSession::class,
    ])
    ->where([
        'pageUid' => '[0-9A-Za-z]{26}',
        'versionUid' => '[0-9A-Za-z]{26}',
    ]);

// Pre-save draft preview. The app origin first issues a short-lived, content-bound
// capability to an authenticated workspace Editor. This artifact-host receiver
// stays session-free and CSRF-exempt so application cookies never cross origins;
// it verifies the capability before reflecting the exact authorized bytes into
// the same opaque-origin sandbox response as a saved artifact.
Route::post('/artifact-previews/draft', ArtifactDraftPreviewController::class)
    ->name('artifact-previews.draft')
    ->middleware([RequireArtifactHostRuntime::class, 'throttle:artifact-previews'])
    ->withoutMiddleware([
        Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\Cookie\Middleware\EncryptCookies::class,
        Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        Illuminate\Session\Middleware\StartSession::class,
        Illuminate\View\Middleware\ShareErrorsFromSession::class,
    ]);

Route::middleware(RejectArtifactHostRuntime::class)->group(function (): void {
    // Healthchecks probe /up on freshly booted stacks, before migrations have
    // run: health must not touch the session store (or write a session row per
    // probe). Session-free like the artifact-previews route above.
    Route::get('/up', static fn () => response('OK', 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]))->name('health')->withoutMiddleware([
        Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\Cookie\Middleware\EncryptCookies::class,
        Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        Illuminate\Session\Middleware\StartSession::class,
        Illuminate\View\Middleware\ShareErrorsFromSession::class,
    ]);

    Route::view('/', 'welcome')->name('home');

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store']);
        Route::get('/login/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
            ->name('login.two-factor');
        Route::post('/login/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
            ->middleware('throttle:artifactflow-two-factor-challenge')
            ->name('login.two-factor.store');
        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:artifactflow-password-reset')
            ->name('password.email');
        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:artifactflow-password-reset')
            ->name('password.update');
    });

    // Public invitation landing, reachable by guests so an invited person who has
    // no account yet can finish registration and join. The controller branches on
    // auth state and never opens general registration — a valid token is required,
    // and a created account is bound to the invited email.
    Route::get('/join/{invitation:token}', [WorkspaceInvitationJoinController::class, 'show'])
        ->middleware('throttle:artifactflow-authenticated')
        ->name('workspace-invitations.join')
        ->missing(static fn () => redirect()
            ->route('login')
            ->withErrors(['invitation' => 'This workspace invitation is no longer valid.']));
    Route::post('/join/{invitation:token}/register', [WorkspaceInvitationJoinController::class, 'register'])
        ->middleware('throttle:artifactflow-invitation-accept')
        ->name('workspace-invitations.join.register')
        ->missing(static fn () => redirect()
            ->route('login')
            ->withErrors(['invitation' => 'This workspace invitation is no longer valid.']));

    Route::middleware([
        'auth',
        EnforceCurrentAuthenticationRevision::class,
        EnforceTwoFactorEnrollment::class,
        'throttle:artifactflow-authenticated',
    ])->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::post('/demo-content', DemoContentController::class)
            ->middleware('throttle:artifactflow-page-writes')
            ->name('demo-content.store');
        Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
        Route::get('/pages/create', [PageController::class, 'create'])->name('pages.create');
        Route::post('/pages/draft-preview-capabilities', ArtifactDraftPreviewCapabilityController::class)
            ->middleware('throttle:artifactflow-draft-preview-capabilities')
            ->name('artifact-previews.draft-capabilities.store');
        Route::post('/pages', [PageController::class, 'store'])
            ->middleware('throttle:artifactflow-page-writes')
            ->name('pages.store');
        Route::get('/pages/{page}', [PageController::class, 'show'])
            ->middleware('can:view,page')
            ->name('pages.show');
        Route::get('/pages/{page}/artifact-preview-url', ArtifactPreviewUrlController::class)
            ->middleware('can:view,page')
            ->name('pages.artifact-preview-url');
        Route::post('/pages/{page}/markdown-preview', MarkdownPreviewController::class)
            ->middleware(['can:update,page', 'throttle:artifactflow-markdown-previews'])
            ->name('pages.markdown-preview');
        Route::put('/pages/{page}/metadata', [PageMetadataController::class, 'update'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.metadata.update');
        Route::put('/pages/{page}/workspace', [PageWorkspaceController::class, 'update'])
            ->middleware(['can:move,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.workspace.update');
        Route::post('/pages/{page}/access', [PageAccessGrantController::class, 'store'])
            ->middleware(['can:manageAccess,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.access.store');
        Route::get('/pages/{page}/access/users/search', [PageAccessGrantController::class, 'searchUsers'])
            ->middleware(['can:manageAccess,page', 'throttle:artifactflow-authenticated'])
            ->name('pages.access-users.search');
        Route::put('/pages/{page}/access-mode', [PageAccessGrantController::class, 'updateMode'])
            ->middleware(['can:changeAccessMode,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.access-mode.update');
        Route::delete('/pages/{page}/access/{grant}', [PageAccessGrantController::class, 'destroy'])
            ->middleware(['can:manageAccess,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.access.destroy');
        Route::post('/pages/{page}/archive', [PageLifecycleController::class, 'archive'])
            ->middleware(['can:archive,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.archive');
        Route::post('/pages/{page}/unarchive', [PageLifecycleController::class, 'unarchive'])
            ->middleware(['can:archive,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.unarchive');
        Route::post('/pages/{page}/mark-approved', [PageLifecycleController::class, 'markApproved'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.mark-approved');
        Route::post('/pages/{page}/return-to-draft', [PageLifecycleController::class, 'returnToDraft'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.return-to-draft');
        Route::post('/pages/{page}/deprecate', [PageLifecycleController::class, 'deprecate'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.deprecate');
        Route::post('/pages/{page}/restore-to-draft', [PageLifecycleController::class, 'restoreToDraft'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.restore-to-draft');
        Route::delete('/pages/{page}', [PageLifecycleController::class, 'destroy'])
            ->middleware(['can:hardDelete,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.destroy');
        Route::post('/pages/{page}/versions', [PageVersionController::class, 'store'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.versions.store');
        Route::get('/pages/{page}/versions/{version}', PageVersionInspectionController::class)
            ->middleware('can:view,page')
            ->name('pages.versions.show');
        Route::get('/pages/{page}/versions/{version}/artifact-preview-url', ArtifactHistoryPreviewUrlController::class)
            ->middleware('can:view,page')
            ->name('pages.versions.artifact-preview-url');
        Route::post('/pages/{page}/versions/{version}/restore', [PageVersionController::class, 'restore'])
            ->middleware(['can:update,page', 'throttle:artifactflow-page-writes'])
            ->name('pages.versions.restore');
        Route::post('/pages/{page}/presence', PagePresenceController::class)
            ->middleware(['can:update,page', 'throttle:artifactflow-page-presence'])
            ->name('pages.presence.update');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/settings/confirm-password', [PasswordConfirmationController::class, 'create'])
            ->name('settings.password.confirm');
        Route::post('/settings/confirm-password', [PasswordConfirmationController::class, 'store'])
            ->middleware('throttle:artifactflow-admin-step-up')
            ->name('settings.password.confirm.store');
        Route::get('/settings/two-factor', [TwoFactorSettingsController::class, 'index'])
            ->name('settings.two-factor.index');
        Route::get('/settings/mcp-tokens', [McpTokenSettingsController::class, 'index'])
            ->name('settings.mcp-tokens.index');
        Route::post('/settings/mcp-tokens', [McpTokenSettingsController::class, 'store'])
            ->middleware('throttle:artifactflow-admin-step-up')
            ->name('settings.mcp-tokens.store');
        Route::delete('/settings/mcp-tokens/{mcpAccessToken}', [McpTokenSettingsController::class, 'destroy'])
            ->name('settings.mcp-tokens.destroy');
        Route::middleware(RequireRecentPasswordConfirmation::class)->group(function (): void {
            Route::post('/settings/two-factor/enroll', [TwoFactorSettingsController::class, 'enroll'])
                ->name('settings.two-factor.enroll');
            Route::post('/settings/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])
                ->name('settings.two-factor.confirm');
            Route::post('/settings/two-factor/disable', [TwoFactorSettingsController::class, 'disable'])
                ->name('settings.two-factor.disable');
            Route::post('/settings/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])
                ->name('settings.two-factor.recovery-codes');
            Route::delete(
                '/settings/two-factor/trusted-devices',
                [TwoFactorSettingsController::class, 'revokeAllTrustedDevices'],
            )->name('settings.two-factor.trusted-devices.destroy-all');
            Route::delete(
                '/settings/two-factor/trusted-devices/{trustedDevice}',
                [TwoFactorSettingsController::class, 'revokeTrustedDevice'],
            )->name('settings.two-factor.trusted-devices.destroy');
        });
        // Every admin route hangs off the administer-system gate so that adding a route
        // here inherits the system-admin guard instead of relying on a re-typed
        // is_system_admin check inside the controller (SystemAdminGateTest locks this in).
        Route::middleware('can:administer-system')->group(function (): void {
            Route::get('/admin/confirm-password', [SystemAdminPasswordConfirmationController::class, 'create'])
                ->name('admin.password.confirm');
            Route::post('/admin/confirm-password', [SystemAdminPasswordConfirmationController::class, 'store'])
                ->middleware('throttle:artifactflow-admin-step-up')
                ->name('admin.password.confirm.store');
            Route::middleware(RequireRecentSystemAdminPasswordConfirmation::class)->group(function (): void {
                Route::get('/admin/users', [SystemUserController::class, 'index'])->name('admin.users.index');
                Route::post('/admin/users', [SystemUserController::class, 'store'])->name('admin.users.store');
                Route::get('/admin/settings', [InstallationSettingsController::class, 'edit'])
                    ->name('admin.settings.edit');
                Route::put('/admin/settings', [InstallationSettingsController::class, 'update'])
                    ->name('admin.settings.update');
            });
        });
        Route::post('/settings/theme', ThemePreferenceController::class)->name('settings.theme');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])
            ->middleware('throttle:artifactflow-workspace-creates')
            ->name('workspaces.store');
        Route::put('/workspaces/{workspace}/settings', WorkspaceSettingsController::class)
            ->middleware(['can:manage,workspace', 'throttle:artifactflow-page-writes'])
            ->name('workspaces.settings.update');
        Route::post('/workspaces/{workspace}/categories', [CategoryController::class, 'store'])
            ->middleware(['can:createCategory,workspace', 'throttle:artifactflow-page-writes'])
            ->name('categories.store');
        Route::put(
            '/workspaces/{workspace}/memberships/{membership}',
            [WorkspaceMembershipController::class, 'update'],
        )->middleware(['can:update,membership,workspace', 'throttle:artifactflow-page-writes'])
            ->name('workspace-memberships.update');
        Route::delete(
            '/workspaces/{workspace}/memberships/{membership}',
            [WorkspaceMembershipController::class, 'destroy'],
        )->middleware(['can:delete,membership,workspace', 'throttle:artifactflow-page-writes'])
            ->name('workspace-memberships.destroy');
        Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])
            ->middleware(['can:invite,workspace', 'throttle:artifactflow-invitations'])
            ->name('workspace-invitations.store');
        // Add a registered human coworker directly, without an email round-trip.
        // UIDs identify directory entries but grant no authority; both routes
        // retain the same invitation permission boundary as email invitations.
        Route::get('/workspaces/{workspace}/collaborators/search', [WorkspaceCollaboratorController::class, 'search'])
            ->middleware(['can:invite,workspace', 'throttle:artifactflow-authenticated'])
            ->name('workspace-collaborators.search');
        Route::post('/workspaces/{workspace}/collaborators', [WorkspaceCollaboratorController::class, 'store'])
            ->middleware(['can:invite,workspace', 'throttle:artifactflow-invitations'])
            ->name('workspace-collaborators.store');
        Route::delete(
            '/workspaces/{workspace}/invitations/{invitation}',
            [WorkspaceInvitationController::class, 'destroy'],
        )->middleware(['can:revoke,invitation,workspace', 'throttle:artifactflow-page-writes'])
            ->name('workspace-invitations.destroy');
        Route::get('/workspace-invitations/{invitation}', [WorkspaceInvitationController::class, 'show'])
            ->name('workspace-invitations.show')
            ->missing(static fn () => redirect()
                ->route('dashboard')
                ->withErrors(['invitation' => 'Workspace invitation cannot be accepted.']));
        // Backwards compatibility for invitation emails delivered before the accept
        // action moved behind a POST confirmation page. Those messages link to
        // GET /accept, whose URL is frozen in the recipient's inbox and cannot be
        // rewritten. Redirect them to the confirmation page instead of a 405; GET
        // never mutates, so acceptance still requires the POST below.
        Route::get('/workspace-invitations/{invitation}/accept', [WorkspaceInvitationController::class, 'acceptLink'])
            ->name('workspace-invitations.accept-link')
            ->missing(static fn () => redirect()
                ->route('dashboard')
                ->withErrors(['invitation' => 'Workspace invitation cannot be accepted.']));
        Route::post('/workspace-invitations/{invitation}/accept', [WorkspaceInvitationController::class, 'accept'])
            ->middleware('throttle:artifactflow-invitation-accept')
            ->name('workspace-invitations.accept')
            ->missing(static fn () => redirect()
                ->route('dashboard')
                ->withErrors(['invitation' => 'Workspace invitation cannot be accepted.']));
        Route::post('/workspaces/{workspace}/switch', SwitchWorkspaceController::class)
            ->middleware('can:switch,workspace')
            ->name('workspaces.switch');
    });
});
