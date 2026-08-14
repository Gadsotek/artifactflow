<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceType;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolves hierarchy mutation targets without disclosing whether a submitted
 * workspace UID is missing or merely outside the actor's administrative reach.
 */
final readonly class WorkspaceHierarchyTargetResolver
{
    public function __construct(private WorkspaceAccess $workspaceAccess)
    {
    }

    /**
     * @throws ModelNotFoundException
     */
    public function resolve(string $actorUid, string $workspaceUid): Workspace
    {
        $workspace = Workspace::query()->find($workspaceUid);

        if (
            !$workspace instanceof Workspace
            || $workspace->type !== WorkspaceType::Shared
            || !$this->workspaceAccess->isAdmin($actorUid, $workspace->uid)
        ) {
            throw (new ModelNotFoundException())->setModel(Workspace::class, [$workspaceUid]);
        }

        return $workspace;
    }
}
