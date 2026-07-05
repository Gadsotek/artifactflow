<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Models\Category;
use App\Models\WorkspaceMembership;

/**
 * Shared metadata boundary rules for page create and update. Deliberately
 * repeats the StorePageRequest length rules: MCP and console callers reach the
 * handlers without the HTTP form request, so the application layer must
 * enforce the boundary itself.
 */
final readonly class PageMetadataRules
{
    public const int MAX_TITLE_CHARACTERS = 255;

    public const int MAX_DESCRIPTION_CHARACTERS = 5000;

    public function normalizeTitle(string $title): string
    {
        $normalizedTitle = trim($title);

        if ($normalizedTitle === '') {
            throw new DomainRuleViolation('Page title must not be blank.');
        }

        // Guard the MCP/CLI callers that reach here without the HTTP StorableText rule:
        // a NUL or malformed UTF-8 byte would otherwise pass mb_strlen and only fail as a
        // 500 when bound to the PostgreSQL text column.
        if (!PageContentEncoding::isStorable($normalizedTitle)) {
            throw new DomainRuleViolation('Page title must not contain control characters or invalid text.');
        }

        if (mb_strlen($normalizedTitle) > self::MAX_TITLE_CHARACTERS) {
            throw new DomainRuleViolation('Page title must be 255 characters or fewer.');
        }

        return $normalizedTitle;
    }

    public function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $normalizedDescription = trim($description);

        if ($normalizedDescription === '') {
            return null;
        }

        if (!PageContentEncoding::isStorable($normalizedDescription)) {
            throw new DomainRuleViolation('Page description must not contain control characters or invalid text.');
        }

        if (mb_strlen($normalizedDescription) > self::MAX_DESCRIPTION_CHARACTERS) {
            throw new DomainRuleViolation('Page description must be 5000 characters or fewer.');
        }

        return $normalizedDescription;
    }

    public function ensureCategoryBelongsToWorkspace(?string $categoryUid, string $workspaceUid): void
    {
        if ($categoryUid === null) {
            return;
        }

        $belongs = Category::query()
            ->where('uid', $categoryUid)
            ->where('workspace_uid', $workspaceUid)
            ->exists();

        if (!$belongs) {
            throw new DomainRuleViolation('Category must belong to the selected workspace.');
        }
    }

    public function ensureOwnerBelongsToWorkspace(string $ownerUserUid, string $workspaceUid): void
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $ownerUserUid)
            ->first();

        if (!$membership instanceof WorkspaceMembership) {
            throw new DomainRuleViolation('Page owner must belong to the selected workspace.');
        }

        if ($membership->role === WorkspaceRole::Reader) {
            throw new DomainRuleViolation('Page owner must be a workspace editor or admin.');
        }
    }
}
