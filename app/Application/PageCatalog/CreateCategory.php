<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Models\Category;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCategory
{
    public function __construct(
        private PageAccess $access,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, CreateCategoryCommand $command): Category
    {
        $actorUid = ActorId::fromUser($actor);

        if (!$this->access->canCreateInWorkspace($actor, $command->workspaceUid)) {
            throw new AuthorizationException('You cannot create categories in this workspace.');
        }

        $workspace = $this->workspace($command->workspaceUid);
        $name = $this->normalizeName($command->name);
        $slug = Str::slug($name);

        if ($slug === '') {
            throw new DomainRuleViolation('Category name must contain letters or numbers.');
        }

        return DB::transaction(function () use ($actor, $actorUid, $name, $slug, $workspace): Category {
            // Lock the workspace row so concurrent creates of the same category serialize
            // here rather than racing the (workspace_uid, slug) unique index and turning
            // the loser into a 23505 -> 500. Under the lock the existence check is atomic,
            // so a duplicate request fails cleanly with the "already exists" rule instead.
            $this->lockWorkspace($workspace->uid);

            // A membership change takes this same workspace lock before it commits. Discard
            // authority cached by the optimistic check above and authorize again after the
            // lock, so a demotion or removal that won the lock also wins the category write.
            $this->access->flushCache();

            if (!$this->access->canCreateInWorkspace($actor, $workspace->uid)) {
                throw new AuthorizationException('You cannot create categories in this workspace.');
            }

            $exists = Category::query()
                ->where('workspace_uid', $workspace->uid)
                ->where('slug', $slug)
                ->exists();

            if ($exists) {
                throw new DomainRuleViolation('Category already exists in this workspace.');
            }

            $category = Category::query()->create([
                'workspace_uid' => $workspace->uid,
                'name' => $name,
                'slug' => $slug,
                'created_by_user_uid' => $actorUid,
            ]);

            $event = $this->events->record(
                eventType: DomainEventType::CategoryCreated,
                aggregateType: 'category',
                aggregateUid: $category->uid,
                payload: [
                    'category_uid' => $category->uid,
                    'workspace_uid' => $workspace->uid,
                    'created_by_user_uid' => $actorUid,
                    'category_slug' => $category->slug,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'category',
                auditableUid: $category->uid,
                action: DomainEventType::CategoryCreated,
                summary: 'Workspace category created.',
                metadata: [
                    'workspace_uid' => $workspace->uid,
                    'category_name' => $category->name,
                    'category_slug' => $category->slug,
                ],
            );

            return $category;
        });
    }

    private function workspace(string $workspaceUid): Workspace
    {
        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }

        return $workspace;
    }

    private function lockWorkspace(string $workspaceUid): void
    {
        $locked = Workspace::query()->whereKey($workspaceUid)->lockForUpdate()->first();

        if (!$locked instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }
    }

    private function normalizeName(string $name): string
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new DomainRuleViolation('Category name must not be blank.');
        }

        // Str::slug() strips a NUL/control byte, so the slug guard below lets a name like
        // "Run\0books" through to the PostgreSQL text column (500). Screen the raw name here.
        if (!PageContentEncoding::isStorable($normalizedName)) {
            throw new DomainRuleViolation('Category name must not contain control characters or invalid text.');
        }

        if (mb_strlen($normalizedName) > 120) {
            throw new DomainRuleViolation('Category name must be 120 characters or fewer.');
        }

        return $normalizedName;
    }
}
