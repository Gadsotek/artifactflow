<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\InstallationSettings;
use App\Models\McpAccessToken;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\TestCase;

final class DomainModelMassAssignmentGuardTest extends TestCase
{
    public function test_workspace_membership_role_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new WorkspaceMembership([
            'workspace_uid' => 'workspace-uid',
            'user_uid' => 'user-uid',
            'role' => WorkspaceRole::Admin,
            'accepted_at' => now(),
        ]);
    }

    public function test_page_access_grant_subject_and_role_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new PageAccessGrant([
            'page_uid' => 'page-uid',
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => 'target-user-uid',
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => 'actor-user-uid',
        ]);
    }

    public function test_page_privilege_and_ownership_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Page([
            'workspace_uid' => 'workspace-uid',
            'owner_user_uid' => 'owner-user-uid',
            'title' => 'Allowed title',
            'slug' => 'allowed-title',
            'type' => PageType::HtmlArtifact,
            'status' => PageStatus::Approved,
        ]);
    }

    public function test_page_access_mode_and_current_version_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Page([
            'title' => 'Allowed title',
            'slug' => 'allowed-title',
            'access_mode' => 'restricted',
            'current_version_uid' => 'forged-version-uid',
        ]);
    }

    public function test_page_version_storage_and_scan_state_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new PageVersion([
            'page_uid' => 'page-uid',
            'version_number' => 999,
            'content_storage_path' => 'pages/other-page/versions/forged.html',
            'content_hash' => hash('sha256', 'forged'),
            'byte_size' => 6,
            'scan_status' => PageSecurityScanStatus::Clean,
            'scan_findings' => [],
            'source' => PageVersionSource::Editor,
            'created_by_user_uid' => 'attacker-user-uid',
            'extracted_text' => 'forged private text',
            'source_text' => 'forged source text',
        ]);
    }

    public function test_workspace_type_and_ownership_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Workspace([
            'name' => 'Forged personal workspace',
            'type' => 'personal',
            'personal_owner_uid' => 'attacker-user-uid',
            'created_by_user_uid' => 'attacker-user-uid',
        ]);
    }

    public function test_workspace_invitation_role_and_token_hash_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new WorkspaceInvitation([
            'workspace_uid' => 'workspace-uid',
            'invited_email' => 'new-member@example.test',
            'role' => WorkspaceRole::Admin,
            'token_hash' => hash('sha256', 'forged-token'),
            'invited_by_user_uid' => 'actor-user-uid',
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_user_two_factor_state_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new User([
            'name' => 'Forged User',
            'email' => 'forged@example.test',
            'password' => 'password',
            'two_factor_secret' => 'forged-secret',
            'two_factor_secret_created_at' => now(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['forged-code-hash'],
            'two_factor_last_used_timestep' => 123,
            'two_factor_required' => true,
        ]);
    }

    public function test_user_email_verification_state_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new User([
            'name' => 'Forged Verified User',
            'email' => 'forged-verified@example.test',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);
    }

    public function test_user_service_account_state_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new User([
            'name' => 'Forged Service Account',
            'email' => 'forged-service@example.test',
            'password' => 'password',
            'is_service_account' => true,
        ]);
    }

    public function test_trusted_device_user_and_token_hash_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new TrustedDevice([
            'user_uid' => 'target-user-uid',
            'token_hash' => hash('sha256', 'forged-token'),
            'label' => 'Forged device',
            'user_agent_summary' => 'Forged browser',
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);
    }

    public function test_mcp_access_token_credentials_and_scopes_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new McpAccessToken([
            'principal_user_uid' => 'attacker-user-uid',
            'name' => 'Forged token',
            'token_hash' => hash('sha256', 'forged-token'),
            'scopes' => ['mcp:search', 'mcp:read', 'mcp:create', 'mcp:update'],
            'workspace_uids' => null,
            'expires_at' => now()->addYear(),
        ]);
    }

    public function test_installation_settings_flags_and_limits_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new InstallationSettings([
            'scope' => 'installation',
            'two_factor_required_for_all_users' => true,
            'realtime_enabled' => true,
        ]);
    }
}
