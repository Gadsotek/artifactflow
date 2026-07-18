<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\McpAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Tests\TestCase;

/**
 * Credential and second-factor material must never travel through array/JSON
 * serialization, where a stray model dump (an API payload, a log line, a
 * `dd()` left in place) would expose it. These models keep the sensitive
 * columns in `$hidden`; this test fails closed if a future column is added
 * without hiding it.
 */
final class SensitiveModelAttributesAreHiddenTest extends TestCase
{
    public function test_mcp_access_token_hash_is_hidden_from_serialization(): void
    {
        $token = new McpAccessToken();
        $token->forceFill(['token_hash' => hash('sha256', 'plain-text-token')]);

        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        // The value is still present on the model for verification code paths;
        // it is only withheld from serialization.
        $this->assertSame(hash('sha256', 'plain-text-token'), $token->token_hash);
    }

    public function test_trusted_device_token_hash_is_hidden_from_serialization(): void
    {
        $device = new TrustedDevice();
        $device->forceFill(['token_hash' => hash('sha256', 'device-token')]);

        $this->assertArrayNotHasKey('token_hash', $device->toArray());
    }

    public function test_workspace_invitation_token_hash_is_hidden_from_serialization(): void
    {
        $invitation = new WorkspaceInvitation();
        $invitation->forceFill(['token_hash' => hash('sha256', 'invitation-token')]);

        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        // The plaintext link secret is a transient property, never an attribute.
        $this->assertArrayNotHasKey('plainToken', $invitation->toArray());
    }

    public function test_user_credentials_and_two_factor_material_are_hidden_from_serialization(): void
    {
        $user = new User();
        $user->forceFill([
            'password' => 'irrelevant-hash',
            'remember_token' => 'remember-token-value',
        ]);
        $user->setRawAttributes([
            ...$user->getAttributes(),
            'two_factor_secret' => 'totp-secret',
            'two_factor_recovery_codes' => 'recovery-codes',
        ]);

        $serialized = $user->toArray();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $attribute) {
            $this->assertArrayNotHasKey($attribute, $serialized);
        }
    }
}
