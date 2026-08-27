<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpRevertInput;
use App\Application\Mcp\Output\McpPdfFactsView;
use App\Application\Mcp\Output\McpRevertedPayload;
use App\Application\PageCatalog\RevertToPreviousVersion;
use App\Application\PageCatalog\RevertToPreviousVersionCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;

/**
 * MCP revert tool: restore the previous page version through the same
 * RevertToPreviousVersion handler and OCC check as the human UI.
 */
final readonly class McpRevertTool
{
    private const string STALE_CONFLICT_MESSAGE = 'The submitted base_version_uid is stale.';

    public function __construct(
        private McpPageResolver $pages,
        private RevertToPreviousVersion $revertToPreviousVersion,
        private McpToolErrorMapper $errors,
        private McpPdfVersionPayload $pdfPayload,
    ) {
    }

    public function handle(User $actor, McpRevertInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            $result = $this->revertToPreviousVersion->handle($actor, new RevertToPreviousVersionCommand(
                pageUid: $page->uid,
                baseVersionUid: $input->baseVersionUid,
                changeSummary: $input->changeSummary,
            ));

            $pdf = null;

            if ($page->type === PageType::Pdf) {
                $pdf = $this->pdfPayload->forVersion($result->restoredVersion);

                if (!$pdf instanceof McpPdfFactsView) {
                    throw new \App\Domain\DomainRuleViolation('PDF processing facts are unavailable.');
                }
            }

            return McpToolResult::success(new McpRevertedPayload(
                pageUid: $page->uid,
                versionUid: $result->restoredVersion->uid,
                currentVersionUid: $result->restoredVersion->uid,
                restoredFromVersionUid: $result->restoredFromVersion->uid,
                pdf: $pdf,
            ));
        }, self::STALE_CONFLICT_MESSAGE);
    }
}
