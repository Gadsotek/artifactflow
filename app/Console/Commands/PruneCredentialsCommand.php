<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\PruneExpiredTrustedDevices;
use App\Application\Mcp\PruneRetiredMcpTokens;
use Illuminate\Console\Command;

final class PruneCredentialsCommand extends Command
{
    protected $signature = 'artifactflow:prune-credentials {--dry-run} {--chunk-size=}';

    protected $description = 'Prune expired trusted-device cookies and retired (expired or revoked) MCP access tokens past their retention windows.';

    public function handle(
        PruneExpiredTrustedDevices $trustedDevices,
        PruneRetiredMcpTokens $mcpTokens,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = $this->chunkSize();

        $deviceCount = $trustedDevices->handle(
            retentionDays: $this->configInt('credentials.trusted_device_retention_days', 0),
            dryRun: $dryRun,
            chunkSize: $chunkSize,
        );
        $tokenCount = $mcpTokens->handle(
            retentionDays: $this->configInt('credentials.mcp_token_retention_days', 30),
            dryRun: $dryRun,
            chunkSize: $chunkSize,
        );

        $this->info(sprintf(
            $dryRun
                ? 'Would prune %d expired trusted %s and %d retired MCP %s.'
                : 'Pruned %d expired trusted %s and %d retired MCP %s.',
            $deviceCount,
            $deviceCount === 1 ? 'device' : 'devices',
            $tokenCount,
            $tokenCount === 1 ? 'token' : 'tokens',
        ));

        return self::SUCCESS;
    }

    private function chunkSize(): int
    {
        $value = $this->option('chunk-size');

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return PruneExpiredTrustedDevices::DEFAULT_DELETE_CHUNK_SIZE;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
