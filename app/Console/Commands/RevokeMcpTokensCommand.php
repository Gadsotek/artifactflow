<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Mcp\McpAccessTokenRevoker;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Console\Command;

final class RevokeMcpTokensCommand extends Command
{
    protected $signature = 'artifactflow:mcp-token-revoke {--uid=} {--email=}';

    protected $description = 'Revoke MCP tokens by UID or principal email without printing token values.';

    public function handle(McpAccessTokenRevoker $revoker): int
    {
        $uidOption = $this->option('uid');
        $emailOption = $this->option('email');
        $uid = is_string($uidOption) ? trim($uidOption) : '';
        $email = is_string($emailOption) ? strtolower(trim($emailOption)) : '';

        if ($uid === '' && $email === '') {
            $this->line('Provide --uid to revoke one token or --email to revoke all tokens for a principal.');

            return 1;
        }

        $query = McpAccessToken::query()->with('principal');

        if ($uid !== '') {
            $query->where('uid', $uid);
        }

        if ($email !== '') {
            $principal = User::query()->where('email', $email)->first();

            if (!$principal instanceof User) {
                $this->line('User does not exist.');

                return 1;
            }

            $query->where('principal_user_uid', $principal->uid);
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->line('No matching MCP tokens.');

            return 1;
        }

        $revoked = 0;
        $unchanged = 0;

        foreach ($tokens as $token) {
            if ($revoker->revoke($token, null, 'cli')) {
                $revoked++;
                $this->line(sprintf('MCP token revoked: %s', $token->uid));

                continue;
            }

            $unchanged++;
            $this->line(sprintf('MCP token already revoked or no longer exists: %s', $token->uid));
        }

        $this->info(sprintf('Revoked %d MCP token(s).', $revoked));
        if ($unchanged > 0) {
            $this->line(sprintf(
                'No change for %d MCP token(s) that were already revoked or no longer exist.',
                $unchanged,
            ));
        }

        return 0;
    }
}
