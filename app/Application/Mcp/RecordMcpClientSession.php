<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\PageCatalog\PageContentEncoding;
use App\Models\McpAccessToken;
use App\Models\McpClientSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Events\SessionInitialized;
use Laravel\Mcp\Exceptions\JsonRpcException;

final readonly class RecordMcpClientSession
{
    private const int MAX_SESSIONS_PER_TOKEN = 64;

    public function __construct(private Request $request)
    {
    }

    public function handle(SessionInitialized $event): void
    {
        $token = $this->request->attributes->get('mcp_access_token');

        if (!$token instanceof McpAccessToken) {
            return;
        }

        [$clientReportedName, $clientReportedVersion] = $this->validatedClientInfo($event->clientInfo);

        DB::transaction(function () use ($token, $event, $clientReportedName, $clientReportedVersion): void {
            $lockedToken = McpAccessToken::query()
                ->whereKey($token->uid)
                ->lockForUpdate()
                ->first();

            if (!$lockedToken instanceof McpAccessToken) {
                return;
            }

            McpClientSession::query()->forceCreate([
                'session_id_hash' => hash('sha256', $event->sessionId),
                'mcp_access_token_uid' => $lockedToken->uid,
                'client_reported_name' => $this->bounded($clientReportedName, 191),
                'client_reported_version' => $this->bounded($clientReportedVersion, 120),
                'protocol_version' => $this->bounded($event->protocolVersion, 32),
                'initialized_at' => now(),
            ]);

            $retainedSessionUids = McpClientSession::query()
                ->where('mcp_access_token_uid', $lockedToken->uid)
                ->orderByDesc('initialized_at')
                ->orderByDesc('uid')
                ->limit(self::MAX_SESSIONS_PER_TOKEN)
                ->pluck('uid');

            McpClientSession::query()
                ->where('mcp_access_token_uid', $lockedToken->uid)
                ->whereNotIn('uid', $retainedSessionUids)
                ->delete();
        });
    }

    /**
     * Laravel MCP documents this payload as string-valued, but raw JSON reaches
     * the event without nested validation. Widen it back to mixed at this
     * boundary before relying on those types.
     *
     * @return array{?string, ?string}
     */
    private function validatedClientInfo(mixed $clientInfo): array
    {
        if ($clientInfo === null) {
            return [null, null];
        }

        if (!is_array($clientInfo) || ($clientInfo !== [] && array_is_list($clientInfo))) {
            $this->invalidClientInfo('clientInfo');
        }

        /** @var array<string, string> $validated */
        $validated = [];

        foreach (['name', 'title', 'version'] as $key) {
            if (!array_key_exists($key, $clientInfo)) {
                continue;
            }

            $value = $clientInfo[$key];

            if (!is_string($value)) {
                $this->invalidClientInfo('clientInfo.' . $key);
            }

            $validated[$key] = $value;
        }

        return [
            $validated['name'] ?? null,
            $validated['version'] ?? null,
        ];
    }

    private function invalidClientInfo(string $field): never
    {
        throw new JsonRpcException(
            message: 'Invalid client information',
            code: -32602,
            requestId: $this->request->input('id'),
            data: ['field' => $field],
        );
    }

    private function bounded(?string $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || !PageContentEncoding::isStorable($normalized)) {
            return null;
        }

        return mb_substr($normalized, 0, $maximum);
    }
}
