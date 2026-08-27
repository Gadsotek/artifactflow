<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Models\McpAccessToken;
use App\Models\McpClientSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class McpProtocolContractTest extends McpTestCase
{
    public function test_invalid_mcp_bearer_rotation_is_still_rate_limited_by_ip(): void
    {
        config([
            'rate_limits.mcp_pre_auth_per_minute' => 3,
            'rate_limits.mcp_per_minute' => 60,
        ]);
        RateLimiter::clear('mcp-ip:203.0.113.77');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
                ->withHeaders(['Authorization' => 'Bearer af_mcp_invalid_' . $attempt])
                ->postJson('/mcp', [
                    'jsonrpc' => '2.0',
                    'id' => 'invalid-' . $attempt,
                    'method' => 'tools/list',
                ])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeaders(['Authorization' => 'Bearer af_mcp_invalid_rotated'])
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 'invalid-4',
                'method' => 'tools/list',
            ])
            ->assertTooManyRequests();
    }

    public function test_mcp_rejects_a_cross_origin_browser_request(): void
    {
        config(['app.url' => 'https://app.artifactflow.test']);
        $service = $this->createServiceAccount('Origin Agent', 'origin-agent@example.test');
        $token = $this->issueToken($service, [McpAccessTokenIssuer::SCOPE_SEARCH])->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Origin' => 'https://evil.test',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'cross-origin',
            'method' => 'tools/list',
        ])->assertForbidden();
    }

    public function test_mcp_rejects_a_cross_origin_request_before_authenticating(): void
    {
        // The Origin gate runs ahead of auth:mcp, so a foreign-origin request is
        // refused (403) without even reaching bearer authentication (401).
        config(['app.url' => 'https://app.artifactflow.test']);

        $this->withHeaders([
            'Authorization' => 'Bearer af_mcp_not_a_real_token',
            'Origin' => 'https://evil.test',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'cross-origin-preauth',
            'method' => 'tools/list',
        ])->assertForbidden();
    }

    public function test_mcp_allows_a_request_without_an_origin_header(): void
    {
        // Non-browser MCP clients (CLI agents) send no Origin header and must keep working.
        config(['app.url' => 'https://app.artifactflow.test']);
        $service = $this->createServiceAccount('No Origin Agent', 'no-origin-agent@example.test');
        $token = $this->issueToken($service, [McpAccessTokenIssuer::SCOPE_SEARCH])->plainTextToken;

        $this->postJsonRpc($token, 'tools/list')->assertOk();
    }

    public function test_mcp_allows_the_application_origin(): void
    {
        config(['app.url' => 'https://app.artifactflow.test']);
        $service = $this->createServiceAccount('App Origin Agent', 'app-origin-agent@example.test');
        $token = $this->issueToken($service, [McpAccessTokenIssuer::SCOPE_SEARCH])->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Origin' => 'https://app.artifactflow.test',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'same-origin',
            'method' => 'tools/list',
        ])->assertOk();
    }

    public function test_mcp_is_not_exposed_to_cross_origin_browsers_via_cors(): void
    {
        // The MCP endpoint is deliberately NOT a CORS path: config/cors.php is absent, so
        // Laravel's default paths (api/*, sanctum/csrf-cookie) exclude /mcp. A browser MCP
        // client at any other host therefore fails its Authorization/Content-Type preflight
        // before a request is dispatched — the preflight carries no Access-Control-Allow-Origin,
        // so there is no origin allow-list to configure. Even the application origin receives
        // no cross-origin grant (same-origin callers never preflight); cross-origin browser
        // access to /mcp does not exist by design.
        config(['app.url' => 'https://app.artifactflow.test']);

        foreach (['https://tools.artifactflow.test', 'https://app.artifactflow.test'] as $origin) {
            $this->options('/mcp', [], [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'authorization,content-type',
            ])->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }

    public function test_mcp_route_is_unreachable_on_the_artifact_host_runtime(): void
    {
        $service = $this->createServiceAccount('Runtime Agent', 'runtime-agent@example.test');
        $token = $this->issueToken($service, ['mcp:search'])->plainTextToken;

        config(['app.runtime_role' => 'artifact-host']);

        $this->postJsonRpc($token, 'tools/list')->assertNotFound();
        $this->get('/mcp')->assertNotFound();
        $this->delete('/mcp')->assertNotFound();
    }

    public function test_mcp_streamable_http_endpoint_rejects_unsupported_http_methods(): void
    {
        $this->get('/mcp')
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST');

        $this->delete('/mcp')
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST');
    }

    public function test_mcp_transport_routes_are_session_free_and_compatibility_methods_are_pre_auth_throttled(): void
    {
        config([
            'session.driver' => 'database',
            'rate_limits.mcp_pre_auth_per_minute' => 2,
        ]);
        RateLimiter::clear('mcp-ip:203.0.113.91');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91'])
                ->get('/mcp')
                ->assertStatus(405)
                ->assertHeader('Allow', 'POST');

            $this->assertSame([], $response->headers->getCookies());
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91'])
            ->get('/mcp')
            ->assertTooManyRequests();

        $deleteResponse = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.92'])
            ->delete('/mcp')
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST');

        $this->assertSame([], $deleteResponse->headers->getCookies());

        $service = $this->createServiceAccount('Stateless Agent', 'stateless-agent@example.test');
        $token = $this->issueToken($service, [McpAccessTokenIssuer::SCOPE_SEARCH])->plainTextToken;
        $postResponse = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.93'])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'MCP-Session-Id' => 'stateless-session',
            ])
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 'stateless-tools-list',
                'method' => 'tools/list',
            ])
            ->assertOk();

        $this->assertSame([], $postResponse->headers->getCookies());
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_lifecycle_notifications_are_acknowledged_with_202_and_no_body(): void
    {
        $service = $this->createServiceAccount('Lifecycle Agent', 'lifecycle-agent@example.test');
        $token = $this->issueToken($service, ['mcp:search'])->plainTextToken;

        // A conforming client completes the lifecycle: initialize (a request), then the
        // mandatory notifications/initialized message (a JSON-RPC notification with no
        // id). Per the Streamable HTTP transport, a notification-only POST MUST be
        // acknowledged with 202 and an empty body, never a JSON-RPC error response.
        $this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'init',
            'method' => 'initialize',
        ])->assertOk();

        $acknowledged = $this->postMcp($token, [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $acknowledged->assertStatus(202);
        $this->assertSame('', $acknowledged->getContent());

        // The client can then continue with normal request/response calls.
        $this->postJsonRpc($token, 'tools/list')->assertOk();
    }

    public function test_initialize_negotiates_the_current_protocol_and_starts_a_standard_session(): void
    {
        $service = $this->createServiceAccount('Negotiation Agent', 'negotiation-agent@example.test');
        $token = $this->issueToken($service, ['mcp:search'])->plainTextToken;

        $initialize = $this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'negotiated-init',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'artifactflow-tests',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        $initialize->assertOk();
        $this->assertSame('2025-11-25', $initialize->json('result.protocolVersion'));
        $this->assertSame('artifactflow', $initialize->json('result.serverInfo.name'));
        $this->assertSame('0.7.0', $initialize->json('result.serverInfo.version'));
        $instructions = $initialize->json('result.instructions');
        $this->assertIsString($instructions);
        $this->assertStringContainsString(
            'Image pixels are not OCR-indexed',
            $instructions,
        );
        $this->assertStringContainsString(
            'update_description',
            $instructions,
        );
        $this->assertStringContainsString(
            'current_version_uid',
            $instructions,
        );
        $this->assertStringContainsString('include: [] for metadata only', $instructions);
        $this->assertStringContainsString('page_origin_producer_uids', $instructions);
        $this->assertStringContainsString('metadata_revision prevents overwriting', $instructions);
        $this->assertStringContainsString(
            'single self-contained HTML document',
            $instructions,
        );
        $this->assertStringContainsString(
            'Do not use CDNs',
            $instructions,
        );
        $this->assertStringContainsString(
            'fetch',
            $instructions,
        );
        $this->assertNotSame('', (string) $initialize->headers->get('MCP-Session-Id'));

        $unsupported = $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'unsupported-init',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2099-01-01',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'artifactflow-tests',
                    'version' => '1.0.0',
                ],
            ],
        ]));

        $this->assertSame(-32602, $unsupported['code']);
        $unsupportedData = $unsupported['data'] ?? null;
        $this->assertIsArray($unsupportedData);
        $this->assertSame('2099-01-01', $unsupportedData['requested'] ?? null);
    }

    public function test_initialize_rejects_malformed_nested_client_metadata_without_recording_a_session(): void
    {
        $service = $this->createServiceAccount('Malformed Client Agent', 'malformed-client-agent@example.test');
        $token = $this->issueToken($service, ['mcp:search'])->plainTextToken;

        foreach ([
            ['name' => ['not-a-string'], 'version' => '1.0.0'],
            ['name' => 'artifactflow-tests', 'version' => 100],
            ['name' => 'artifactflow-tests', 'version' => '1.0.0', 'title' => false],
            ['not-an-object'],
        ] as $index => $clientInfo) {
            $error = $this->jsonRpcErrorPayload($this->postMcp($token, [
                'jsonrpc' => '2.0',
                'id' => 'malformed-client-' . $index,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => $clientInfo,
                ],
            ]));

            $this->assertSame(-32602, $error['code']);
            $this->assertSame('Invalid client information', $error['message']);
        }

        $this->assertDatabaseCount('mcp_client_sessions', 0);
    }

    public function test_initialize_caps_client_report_sessions_per_access_token(): void
    {
        config([
            'rate_limits.mcp_pre_auth_per_minute' => 1_000,
            'rate_limits.mcp_per_minute' => 1_000,
        ]);

        $service = $this->createServiceAccount('Session Retention Agent', 'session-retention-agent@example.test');
        $token = $this->issueToken($service, ['mcp:search'])->plainTextToken;
        $sessionIds = [];

        foreach (range(1, 65) as $index) {
            $response = $this->postMcp($token, [
                'jsonrpc' => '2.0',
                'id' => 'retention-init-' . $index,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => [
                        'name' => 'retention-client-' . $index,
                        'version' => '1.0.0',
                    ],
                ],
            ])->assertOk();
            $sessionId = $response->headers->get('MCP-Session-Id');
            $this->assertIsString($sessionId);
            $sessionIds[] = $sessionId;
        }

        $accessToken = McpAccessToken::query()->where('principal_user_uid', $service->uid)->sole();
        $this->assertSame(
            64,
            McpClientSession::query()->where('mcp_access_token_uid', $accessToken->uid)->count(),
        );
        $this->assertDatabaseMissing('mcp_client_sessions', [
            'session_id_hash' => hash('sha256', $sessionIds[0]),
        ]);
        $this->assertDatabaseHas('mcp_client_sessions', [
            'session_id_hash' => hash('sha256', $sessionIds[64]),
            'client_reported_name' => 'retention-client-65',
        ]);
    }
}
