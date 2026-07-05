<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateTag
{
    public function __construct(
        private PageAccess $access,
        private TagSynchronizer $tags,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, CreateTagCommand $command): Tag
    {
        $this->access->ensureCanCreateInWorkspace($actor, $command->workspaceUid);
        $normalizedNames = $this->tags->uniqueNormalizedNames([$command->name]);
        $name = $normalizedNames[0] ?? null;

        if (!is_string($name)) {
            throw new DomainRuleViolation('Tag name must not be blank.');
        }

        $slug = Str::slug($name);
        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actor, $actorUid, $command, $name, $slug): Tag {
            // Standalone tags use a workspace only as their authority boundary. Lock that
            // workspace before touching the installation-wide vocabulary so a concurrent
            // membership demotion/removal serializes with this write, then reauthorize from
            // fresh state instead of trusting the optimistic request-scoped cache above.
            $this->lockWorkspace($command->workspaceUid);
            $this->access->flushCache();
            $this->access->ensureCanCreateInWorkspace($actor, $command->workspaceUid);

            $tag = Tag::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'created_by_user_uid' => $actorUid,
                ],
            );

            if (!$tag->wasRecentlyCreated) {
                return $tag;
            }

            $event = $this->events->record(
                eventType: DomainEventType::TagCreated,
                aggregateType: 'tag',
                aggregateUid: $tag->uid,
                payload: [
                    'tag_uid' => $tag->uid,
                    'created_by_user_uid' => $actorUid,
                    'tag_slug' => $tag->slug,
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'tag',
                auditableUid: $tag->uid,
                action: DomainEventType::TagCreated,
                summary: 'Installation tag created.',
                metadata: [
                    'tag_name' => $tag->name,
                    'tag_slug' => $tag->slug,
                ],
            );

            return $tag;
        });
    }

    private function lockWorkspace(string $workspaceUid): void
    {
        $locked = Workspace::query()->whereKey($workspaceUid)->lockForUpdate()->first();

        if (!$locked instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }
    }
}
