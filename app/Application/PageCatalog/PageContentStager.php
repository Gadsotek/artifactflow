<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class PageContentStager
{
    public function stageDerivative(PreparedArtifactDerivative $derivative): PreparedArtifactDerivative
    {
        return $derivative->withStagedContent($this->stage(
            content: $derivative->content,
            storageFilename: $derivative->storageFilename,
            namespace: 'derivatives',
            failureMessage: 'Failed to stage artifact derivative.',
        ));
    }

    public function stageForPersistence(PreparedPageContent $prepared): PreparedPageContent
    {
        if ($prepared->requiresPrivateStaging) {
            $prepared = $prepared->withStagedContent($this->stage(
                content: $prepared->content,
                storageFilename: $prepared->storageFilename,
                namespace: $prepared->derivative === null && $prepared->storageFilename === 'document.pdf'
                    ? 'pdf'
                    : 'artifacts',
                failureMessage: 'Failed to stage artifact content.',
            ));
        }

        if ($prepared->derivative instanceof PreparedArtifactDerivative) {
            try {
                $prepared = $prepared->withStagedDerivative(
                    $this->stageDerivative($prepared->derivative),
                );
            } catch (Throwable $exception) {
                $prepared->discardStaging();

                throw $exception;
            }
        }

        return $prepared;
    }

    private function stage(
        string $content,
        string $storageFilename,
        string $namespace,
        string $failureMessage,
    ): StagedArtifactContent {
        $storagePath = sprintf(
            'staging/%s/%s/%s',
            $namespace,
            (string) Str::ulid(),
            $storageFilename,
        );
        $stagedContent = new StagedArtifactContent(
            storagePath: $storagePath,
            contentHash: hash('sha256', $content),
            byteSize: strlen($content),
        );

        try {
            $stored = Storage::disk('artifacts')->put($storagePath, $content);
        } catch (Throwable $exception) {
            $stagedContent->discard();

            throw new RuntimeException($failureMessage, 0, $exception);
        }

        if (!$stored) {
            $stagedContent->discard();

            throw new RuntimeException($failureMessage);
        }

        return $stagedContent;
    }
}
