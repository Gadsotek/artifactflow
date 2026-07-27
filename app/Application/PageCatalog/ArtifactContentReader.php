<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

final readonly class ArtifactContentReader
{
    public function __construct(
        private InstallationLimitSettings $limits,
    ) {
    }

    public function read(string $storagePath, ?int $maximumBytes = null): ?string
    {
        $disk = Storage::disk('artifacts');
        $maxBytes = $maximumBytes === null
            ? $this->configuredMaxBytes()
            : min($this->configuredMaxBytes(), $maximumBytes);

        try {
            if ($this->isOversized($disk, $storagePath, $maxBytes)) {
                return null;
            }

            $stream = $disk->readStream($storagePath);

            if (!is_resource($stream)) {
                return null;
            }

            try {
                $content = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        } catch (FilesystemException) {
            return null;
        }

        return is_string($content) ? $content : null;
    }

    public function isAvailable(string $storagePath): bool
    {
        $disk = Storage::disk('artifacts');

        try {
            if ($this->isOversized($disk, $storagePath, $this->configuredMaxBytes())) {
                return false;
            }

            $stream = $disk->readStream($storagePath);

            if (!is_resource($stream)) {
                return false;
            }

            fclose($stream);

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    private function isOversized(Filesystem $disk, string $storagePath, int $maxBytes): bool
    {
        $byteSize = $disk->size($storagePath);

        if ($byteSize <= $maxBytes) {
            return false;
        }

        Log::warning('artifact_content.read_rejected', [
            'byte_size' => $byteSize,
            'max_bytes' => $maxBytes,
            'reason' => 'artifact_too_large',
            'storage_path_hash' => hash('sha256', $storagePath),
        ]);

        return true;
    }

    private function configuredMaxBytes(): int
    {
        return $this->limits->integer('pages.artifact_max_bytes');
    }
}
