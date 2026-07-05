<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final class ArtifactIntegrityVerificationResult
{
    public int $checked = 0;

    public int $ok = 0;

    public int $missingFile = 0;

    public int $hashMismatch = 0;

    public function recordOk(): void
    {
        $this->checked++;
        $this->ok++;
    }

    public function recordMissingFile(): void
    {
        $this->checked++;
        $this->missingFile++;
    }

    public function recordHashMismatch(): void
    {
        $this->checked++;
        $this->hashMismatch++;
    }

    public function hasFailures(): bool
    {
        return $this->missingFile > 0 || $this->hashMismatch > 0;
    }

    /**
     * @return array{checked: int, ok: int, missing_file: int, hash_mismatch: int}
     */
    public function toArray(): array
    {
        return [
            'checked' => $this->checked,
            'ok' => $this->ok,
            'missing_file' => $this->missingFile,
            'hash_mismatch' => $this->hashMismatch,
        ];
    }
}
