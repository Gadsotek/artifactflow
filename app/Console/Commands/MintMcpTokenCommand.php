<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Mcp\IssueCliMcpToken;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Domain\DomainRuleViolation;
use Illuminate\Console\Command;

final class MintMcpTokenCommand extends Command
{
    protected $signature = 'artifactflow:mcp-token-create {--name=} {--email=} {--workspace=*} {--scope=*} {--ttl-days=30}';

    protected $description = 'Create an Editor-scoped service account MCP token for selected workspaces.';

    public function handle(IssueCliMcpToken $issueCliMcpToken): int
    {
        $emailOption = $this->option('email');
        $nameOption = $this->option('name');
        $workspaceOptions = $this->option('workspace');
        $scopeOptions = $this->option('scope');
        $ttlOption = $this->option('ttl-days');

        $email = is_string($emailOption) ? strtolower(trim($emailOption)) : '';
        $name = is_string($nameOption) && trim($nameOption) !== '' ? trim($nameOption) : 'Artifact Flow MCP Agent';
        $workspaceUids = array_values(array_filter($workspaceOptions, 'is_string'));
        $scopes = $scopeOptions !== []
            ? array_values(array_filter($scopeOptions, 'is_string'))
            : [
                McpAccessTokenIssuer::SCOPE_SEARCH,
                McpAccessTokenIssuer::SCOPE_READ,
                McpAccessTokenIssuer::SCOPE_CREATE,
                McpAccessTokenIssuer::SCOPE_UPDATE,
            ];
        $ttlDays = is_string($ttlOption) && ctype_digit($ttlOption) ? (int) $ttlOption : 30;

        if ($email === '') {
            $this->line('A service-account email is required.');

            return 1;
        }

        if ($workspaceUids === []) {
            $this->line('At least one --workspace UID is required.');

            return 1;
        }

        if ($ttlDays < 1) {
            $this->line('Token TTL must be at least one day.');

            return 1;
        }

        try {
            $issued = $issueCliMcpToken->handle($email, $name, $workspaceUids, $scopes, $ttlDays);
        } catch (DomainRuleViolation $exception) {
            $this->line($exception->getMessage());

            return 1;
        }

        $this->info(sprintf('MCP service account ready: %s', $email));
        $this->line(sprintf('MCP token UID: %s', $issued->accessToken->uid));
        $this->line(sprintf('MCP token: %s', $issued->plainTextToken));

        return 0;
    }
}
