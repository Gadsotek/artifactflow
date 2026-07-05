<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
final class PageFactory extends Factory
{
    protected $model = Page::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(3), '.');

        return [
            'workspace_uid' => fn (): string => Workspace::query()->forceCreate([
                'name' => 'Factory Workspace ' . Str::lower(Str::random(8)),
                'type' => WorkspaceType::Shared,
            ])->uid,
            'owner_user_uid' => User::factory(),
            'parent_page_uid' => null,
            'category_uid' => null,
            'current_version_uid' => null,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'access_mode' => PageAccessMode::Inherited,
            'type' => PageType::HtmlArtifact,
            'status' => PageStatus::Draft,
        ];
    }
}
