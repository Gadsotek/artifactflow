<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Domain\DomainRuleViolation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class McpAccessTokenIssuerTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_scoped_token_beyond_the_max_ttl_is_rejected(): void
    {
        $issuer = app(McpAccessTokenIssuer::class);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Write-capable MCP tokens must expire within 90 days.');

        try {
            $issuer->issue(
                principal: $this->serviceAccount(),
                name: 'over-ttl',
                scopes: [McpAccessTokenIssuer::SCOPE_CREATE],
                expiresAt: Carbon::now()->addDays(McpAccessTokenIssuer::MAX_WRITE_SCOPE_TTL_DAYS + 1),
            );
        } finally {
            // The invariant must fail before any token row is written.
            $this->assertSame(0, McpAccessToken::query()->count());
        }
    }

    public function test_write_scoped_token_at_the_max_ttl_is_allowed(): void
    {
        $issued = app(McpAccessTokenIssuer::class)->issue(
            principal: $this->serviceAccount(),
            name: 'at-ttl',
            scopes: [McpAccessTokenIssuer::SCOPE_CREATE],
            expiresAt: Carbon::now()->addDays(McpAccessTokenIssuer::MAX_WRITE_SCOPE_TTL_DAYS),
        );

        $this->assertNotSame('', $issued->plainTextToken);
        $this->assertSame(1, McpAccessToken::query()->count());
    }

    public function test_read_only_token_may_exceed_the_write_ttl_cap(): void
    {
        $issued = app(McpAccessTokenIssuer::class)->issue(
            principal: $this->serviceAccount(),
            name: 'read-long',
            scopes: [McpAccessTokenIssuer::SCOPE_READ, McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: Carbon::now()->addDays(365),
        );

        $this->assertNotSame('', $issued->plainTextToken);
        $this->assertSame(1, McpAccessToken::query()->count());
    }

    public function test_read_only_token_beyond_the_absolute_ttl_ceiling_is_rejected(): void
    {
        $issuer = app(McpAccessTokenIssuer::class);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('MCP tokens must expire within 365 days.');

        try {
            $issuer->issue(
                principal: $this->serviceAccount(),
                name: 'read-forever',
                scopes: [McpAccessTokenIssuer::SCOPE_READ, McpAccessTokenIssuer::SCOPE_SEARCH],
                expiresAt: Carbon::now()->addDays(McpAccessTokenIssuer::MAX_TOKEN_TTL_DAYS + 10),
            );
        } finally {
            $this->assertSame(0, McpAccessToken::query()->count());
        }
    }

    public function test_token_issuance_is_atomic_when_the_audit_write_fails(): void
    {
        // Force the audit journal write (the final step of issuance) to fail
        // without disturbing the model's real boot listeners: run against a
        // cloned event dispatcher that additionally throws on AuditEntry create.
        $dispatcher = AuditEntry::getEventDispatcher();
        if (!$dispatcher instanceof Dispatcher) {
            $this->fail('Expected an event dispatcher to be bound to the model.');
        }
        $scoped = clone $dispatcher;
        $scoped->listen(
            'eloquent.creating: ' . AuditEntry::class,
            static fn (): never => throw new RuntimeException('audit backend unavailable'),
        );
        AuditEntry::setEventDispatcher($scoped);

        try {
            app(McpAccessTokenIssuer::class)->issue(
                principal: $this->serviceAccount(),
                name: 'atomic',
                scopes: [McpAccessTokenIssuer::SCOPE_READ],
                expiresAt: Carbon::now()->addDays(30),
            );
            $this->fail('Expected the failing audit write to abort issuance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit backend unavailable', $exception->getMessage());
        } finally {
            AuditEntry::setEventDispatcher($dispatcher);
        }

        // The token row and its domain-event journal entry must roll back with the
        // failed audit write: no live credential without a traceability record.
        $this->assertSame(0, McpAccessToken::query()->count());
        $this->assertSame(0, DomainEvent::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    private function serviceAccount(): User
    {
        $user = User::query()->create([
            'name' => 'Agent',
            'email' => 'agent@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $user->forceFill(['is_service_account' => true])->save();

        return $user;
    }
}
