<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Every durable domain event type in the system. DomainEventRecorder accepts
 * only this enum, so a typo can no longer silently split the audit trail into
 * a new event stream; adding an event type means adding a case here.
 */
enum DomainEventType: string
{
    case CategoryCreated = 'category.created';
    case InstallationLimitsUpdated = 'installation.limits.updated';
    case InstallationTwoFactorEnforcementOperatorDisabled = 'installation.two_factor_enforcement.operator_disabled';
    case McpTokenCreated = 'mcp_token.created';
    case McpTokenRevoked = 'mcp_token.revoked';
    case PageAccessGrantCreated = 'page.access_grant.created';
    case PageAccessGrantRevoked = 'page.access_grant.revoked';
    case PageAccessGrantUpdated = 'page.access_grant.updated';
    case PageAccessModeUpdated = 'page.access_mode.updated';
    case PageArchived = 'page.archived';
    case PageArtifactDeleteFailed = 'page.artifact_delete_failed';
    case PageContentChangeReturnedToDraft = 'page.content_change_returned_to_draft';
    case PageCreated = 'page.created';
    case PageDeprecated = 'page.deprecated';
    case PageDeprecationRestored = 'page.deprecation_restored';
    case PageHardDeleted = 'page.hard_deleted';
    case PageMarkedApproved = 'page.marked_approved';
    case PageMetadataUpdated = 'page.metadata.updated';
    case PageOwnershipTransferred = 'page.ownership.transferred';
    case PageReturnedToDraft = 'page.returned_to_draft';
    case PageSecretScanBlocked = 'page.secret_scan.blocked';
    case PageSecurityWarningsRecorded = 'page.security_warnings.recorded';
    case PageUnarchived = 'page.unarchived';
    case PageVersionCreated = 'page.version.created';
    case PageVersionPruned = 'page.version.pruned';
    case PageVersionRestored = 'page.version.restored';
    case PageWorkspaceMoved = 'page.workspace.moved';
    case TagCreated = 'tag.created';
    case UserCreated = 'user.created';
    case UserLoggedIn = 'user.logged_in';
    case UserPasswordReset = 'user.password_reset';
    case UserSystemAdminBootstrapped = 'user.system_admin.bootstrapped';
    case UserSystemAdminPromoted = 'user.system_admin.promoted';
    case UserThemePreferenceChanged = 'user.theme_preference.changed';
    case UserTwoFactorDisabled = 'user.two_factor.disabled';
    case UserTwoFactorEnabled = 'user.two_factor.enabled';
    case UserTwoFactorOperatorDisabled = 'user.two_factor.operator_disabled';
    case UserTwoFactorRecoveryCodesRegenerated = 'user.two_factor.recovery_codes_regenerated';
    case UserTwoFactorTrustedDeviceAdded = 'user.two_factor.trusted_device_added';
    case UserTwoFactorTrustedDeviceRevoked = 'user.two_factor.trusted_device_revoked';
    case UserTwoFactorTrustedDevicesRevokedAll = 'user.two_factor.trusted_devices_revoked_all';
    case WorkspaceInvitationAccepted = 'workspace.invitation.accepted';
    case WorkspaceInvitationCreated = 'workspace.invitation.created';
    case WorkspaceInvitationReactivated = 'workspace.invitation.reactivated';
    case WorkspaceInvitationRevoked = 'workspace.invitation.revoked';
    case WorkspaceInvitationRoleChanged = 'workspace.invitation.role_changed';
    case WorkspaceMembershipAdded = 'workspace.membership.added';
    case WorkspaceMembershipRemoved = 'workspace.membership.removed';
    case WorkspaceMembershipRoleChanged = 'workspace.membership.role_changed';
    case WorkspacePersonalCreated = 'workspace.personal.created';
    case WorkspaceSettingsUpdated = 'workspace.settings.updated';
    case WorkspaceSharedCreated = 'workspace.shared.created';
}
