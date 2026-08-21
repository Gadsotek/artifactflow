<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class PageContentStager
{
    public function stageForPersistence(PreparedPageContent $prepared): PreparedPageContent
    {
        if (!$prepared->requiresPrivateStaging) {
            return $prepared;
        }

        $storagePath = sprintf(
            'staging/pdf/%s/%s',
            (string) Str::ulid(),
            $prepared->storageFilename,
        );
        $stagedContent = new StagedArtifactContent(
            storagePath: $storagePath,
            contentHash: hash('sha256', $prepared->content),
            byteSize: strlen($prepared->content),
        );

        try {
            $stored = Storage::disk('artifacts')->put($storagePath, $prepared->content);
        } catch (Throwable $exception) {
            $stagedContent->discard();

            throw new RuntimeException('Failed to stage PDF content.', 0, $exception);
        }

        if (!$stored) {
            $stagedContent->discard();

            throw new RuntimeException('Failed to stage PDF content.');
        }

        return $prepared->withStagedContent($stagedContent);
    }
}
