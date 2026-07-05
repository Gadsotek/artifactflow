<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Application\PageCatalog\TagSynchronizer;
use App\Domain\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A NUL byte survives trim() (it is only stripped from the ends, so a mid-string NUL
 * remains) and Str::slug() (which drops it entirely), and would otherwise reach a
 * PostgreSQL text column as a hard 500 or an internal MCP error. Every free-text write
 * boundary that the page/metadata HTTP requests do not cover -- user, workspace, category,
 * and tag names -- must reject it as a clean DomainRuleViolation at the boundary instead.
 */
final class StorableTextBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_rejects_a_name_with_a_nul_byte(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('User name must not contain control characters or invalid text.');

        app(CreateUser::class)->handle("Ad\0min", 'nul-user@example.test', 'correct horse battery staple');
    }

    public function test_create_shared_workspace_rejects_a_name_with_a_nul_byte(): void
    {
        $actor = app(CreateUser::class)->handle('Owner', 'nul-ws-owner@example.test', 'correct horse battery staple');

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Workspace name must not contain control characters or invalid text.');

        app(CreateSharedWorkspace::class)->handle($actor, "Plat\0form");
    }

    public function test_create_category_rejects_a_name_with_a_nul_byte(): void
    {
        $actor = app(CreateUser::class)->handle('Owner', 'nul-cat-owner@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($actor, 'Platform Team');

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Category name must not contain control characters or invalid text.');

        app(CreateCategory::class)->handle($actor, new CreateCategoryCommand(
            workspaceUid: $workspace->uid,
            name: "Run\0books",
        ));
    }

    public function test_tag_normalization_rejects_a_name_with_a_nul_byte(): void
    {
        // A slug of "ru\0nbook" is "runbook" (non-empty), so the searchable-characters guard
        // passes it; the storable-text screen must reject the NUL-bearing name first.
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Tag names must not contain control characters or invalid text.');

        app(TagSynchronizer::class)->uniqueNormalizedNames(["ru\0nbook"]);
    }
}
