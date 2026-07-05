<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Console\Command;

final class ListMcpTokensCommand extends Command
{
    protected $signature = 'artifactflow:mcp-token-list {--email=}';

    protected $description = 'List MCP token metadata for a principal without printing token values.';

    public function handle(): int
    {
        $emailOption = $this->option('email');
        $email = is_string($emailOption) ? strtolower(trim($emailOption)) : '';

        if ($email === '') {
            $this->line('A principal email is required.');

            return 1;
        }

        $principal = User::query()->where('email', $email)->first();

        if (!$principal instanceof User) {
            $this->line('User does not exist.');

            return 1;
        }

        $tokens = McpAccessToken::query()
            ->where('principal_user_uid', $principal->uid)
            ->orderByDesc('created_at')
            ->get();

        if ($tokens->isEmpty()) {
            $this->line(sprintf('No MCP tokens for %s.', $principal->email));

            return 0;
        }

        $this->info(sprintf('MCP tokens for %s:', $principal->email));

        foreach ($tokens as $token) {
            $this->line(sprintf(
                '- %s name="%s" scopes="%s" expires_at=%s last_used_at=%s revoked_at=%s',
                $token->uid,
                $token->name,
                implode(',', $token->scopes),
                $token->expires_at->toISOString(),
                $token->last_used_at?->toISOString() ?? 'never',
                $token->revoked_at?->toISOString() ?? 'active',
            ));
        }

        return 0;
    }
}
