<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

final readonly class VerifyArtifactIntegrity
{
    private const int ALL_BATCH_SIZE = 500;

    public function handle(int $sampleSize, bool $all): ArtifactIntegrityVerificationResult
    {
        $query = PageVersion::query()
            ->select(['uid', 'content_storage_path', 'content_hash'])
            ->with('derivatives:uid,page_version_uid,storage_path,content_hash');

        $disk = Storage::disk('artifacts');
        $result = new ArtifactIntegrityVerificationResult();

        if (!$all) {
            foreach ($query->inRandomOrder()->limit($sampleSize)->get() as $version) {
                /** @var PageVersion $version */
                $this->verifyVersion($version, $disk, $result);
            }

            return $result;
        }

        $query
            ->orderBy('uid')
            ->chunkById(
                self::ALL_BATCH_SIZE,
                /**
                 * @param EloquentCollection<int, PageVersion> $versions
                 */
                function (EloquentCollection $versions) use ($disk, $result): void {
                    foreach ($versions as $version) {
                        $this->verifyVersion($version, $disk, $result);
                    }
                },
                'uid',
            );

        return $result;
    }

    private function verifyVersion(
        PageVersion $version,
        Filesystem $disk,
        ArtifactIntegrityVerificationResult $result,
    ): void {
        $this->verifyBlob($version->content_storage_path, $version->content_hash, $disk, $result);

        foreach ($version->derivatives as $derivative) {
            /** @var PageVersionDerivative $derivative */
            $this->verifyBlob($derivative->storage_path, $derivative->content_hash, $disk, $result);
        }
    }

    private function verifyBlob(
        string $path,
        string $expectedHash,
        Filesystem $disk,
        ArtifactIntegrityVerificationResult $result,
    ): void {
        try {
            if (!$disk->exists($path)) {
                $result->recordMissingFile();

                return;
            }

            $content = $disk->get($path);
        } catch (FilesystemException) {
            $result->recordMissingFile();

            return;
        }

        if (!is_string($content)) {
            $result->recordMissingFile();

            return;
        }

        if (!hash_equals($expectedHash, hash('sha256', $content))) {
            $result->recordHashMismatch();

            return;
        }

        $result->recordOk();
    }
}
