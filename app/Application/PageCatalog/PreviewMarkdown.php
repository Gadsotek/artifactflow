<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class PreviewMarkdown
{
    public function __construct(
        private PageAccess $access,
        private MarkdownPageRenderer $renderer,
        private InstallationLimitSettings $limits,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, PreviewMarkdownCommand $command): string
    {
        $page = PageFinder::requireByUid($command->pageUid);

        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        if ($page->type !== PageType::Markdown) {
            throw new DomainRuleViolation('Only Markdown pages support live preview.');
        }

        if (trim($command->content) === '') {
            throw new DomainRuleViolation('Markdown preview content must not be blank.');
        }

        if (strlen($command->content) > $this->limits->integer('pages.max_markdown_bytes')) {
            throw new DomainRuleViolation('Markdown preview exceeds the configured size limit.');
        }

        return $this->renderer->renderForPage($actor, $page, $command->content);
    }
}
