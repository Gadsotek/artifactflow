<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Mcp\McpRequestContext;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\PageSecurityScanStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class PageVersionWriter
{
    public function __construct(
        private PageTextExtractor $textExtractor,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpRequestContext $mcpContext,
        private WorkspaceStorageQuota $storageQuota,
    ) {
    }

    public function writeInitialVersion(
        Page $page,
        string $content,
        ContentSecurityScan $scan,
        PageVersionSource $source,
        string $actorUid,
    ): PageVersion {
        return $this->write(
            page: $page,
            content: $content,
            scan: $scan,
            source: $source,
            actorUid: $actorUid,
            versionNumber: 1,
            failureMessage: 'Failed to store page content.',
        );
    }

    public function appendVersion(
        Page $page,
        string $content,
        ContentSecurityScan $scan,
        PageVersionSource $source,
        string $actorUid,
    ): PageVersion {
        return $this->write(
            page: $page,
            content: $content,
            scan: $scan,
            source: $source,
            actorUid: $actorUid,
            versionNumber: $this->nextVersionNumber($page),
            failureMessage: 'Failed to store page version content.',
        );
    }

    private function write(
        Page $page,
        string $content,
        ContentSecurityScan $scan,
        PageVersionSource $source,
        string $actorUid,
        int $versionNumber,
        string $failureMessage,
    ): PageVersion {
        // Last-line guard shared by every write path (editor, upload, MCP): the
        // derived source_text/extracted_text columns are PostgreSQL text and
        // cannot hold a NUL byte or malformed UTF-8. HTTP requests are screened
        // earlier for a field-level 422; this backstop keeps the MCP path and any
        // future caller from turning bad bytes into a 500 mid-transaction.
        if (!PageContentEncoding::isStorable($content)) {
            throw new DomainRuleViolation('Page content must be valid UTF-8 text without control characters.');
        }

        $versionUid = (string) Str::ulid();
        $storagePath = $this->storagePath($page, $page->type, $versionNumber, $versionUid);

        if (Storage::disk('artifacts')->put($storagePath, $content) === false) {
            Storage::disk('artifacts')->delete($storagePath);

            throw new RuntimeException($failureMessage);
        }

        try {
            $version = PageVersion::query()->forceCreate([
                'uid' => $versionUid,
                'page_uid' => $page->uid,
                'version_number' => $versionNumber,
                'content_storage_path' => $storagePath,
                'content_hash' => hash('sha256', $content),
                'byte_size' => strlen($content),
                'scan_status' => $scan->hasWarningFindings()
                    ? PageSecurityScanStatus::Warnings
                    : PageSecurityScanStatus::Clean,
                'scan_findings' => $scan->persistedFindings(),
                'source' => $source,
                'created_by_user_uid' => $actorUid,
                // Cap at write like source_text: search only indexes and snippets the
                // first MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS, so persisting more is dead
                // weight that TOAST-bloats the row.
                'extracted_text' => mb_substr(
                    $this->textExtractor->extract($page->type, $content),
                    0,
                    PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
                ),
                'source_text' => mb_substr(
                    $this->textExtractor->extractSource($page->type, $content),
                    0,
                    PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
                ),
            ]);

            $this->storageQuota->recordBytesStored($page->workspace_uid, strlen($content));
            $this->clearPreviousCurrentVersionExtractedText($page);
            $this->recordPageVersionCreated($page, $version, $actorUid);

            return $version;
        } catch (Throwable $exception) {
            Storage::disk('artifacts')->delete($storagePath);

            throw $exception;
        }
    }

    /**
     * Only the current version's extracted_text is ever read (search vector,
     * search snippets, MCP read). Restore, revert, and reindex re-extract from
     * the stored artifact file, so the text of the version being replaced can
     * be dropped instead of keeping a full copy per historic version.
     */
    private function clearPreviousCurrentVersionExtractedText(Page $page): void
    {
        if ($page->current_version_uid === null) {
            return;
        }

        PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->update(['extracted_text' => null]);
    }

    private function nextVersionNumber(Page $page): int
    {
        $maxVersionNumber = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->max('version_number');

        if (is_int($maxVersionNumber)) {
            return $maxVersionNumber + 1;
        }

        if (is_string($maxVersionNumber) && ctype_digit($maxVersionNumber)) {
            return ((int) $maxVersionNumber) + 1;
        }

        return 1;
    }

    private function storagePath(Page $page, PageType $type, int $versionNumber, string $versionUid): string
    {
        $extension = $type === PageType::HtmlArtifact ? 'html' : 'md';
        $filename = $type === PageType::HtmlArtifact ? 'index' : 'source';

        return sprintf(
            'pages/%s/versions/%d-%s/%s.%s',
            $page->uid,
            $versionNumber,
            $versionUid,
            $filename,
            $extension,
        );
    }

    private function recordPageVersionCreated(Page $page, PageVersion $version, string $actorUid): void
    {
        $mcpMetadata = $this->mcpContext->auditMetadata();
        $event = $this->events->record(
            eventType: DomainEventType::PageVersionCreated,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'page_version_uid' => $version->uid,
                'version_number' => $version->version_number,
                'created_by_user_uid' => $actorUid,
                'content_hash' => $version->content_hash,
                'byte_size' => $version->byte_size,
                'scan_status' => $version->scan_status->value,
                'source' => $version->source->value,
            ] + $mcpMetadata,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page_version',
            auditableUid: $version->uid,
            action: DomainEventType::PageVersionCreated,
            summary: 'Page version created.',
            metadata: [
                'page_uid' => $page->uid,
                'version_number' => $version->version_number,
                'byte_size' => $version->byte_size,
                'scan_status' => $version->scan_status->value,
                'source' => $version->source->value,
            ] + $mcpMetadata,
        );
    }
}
