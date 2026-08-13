<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\ActorId;
use App\Application\Identity\WorkspaceHierarchyTargetResolver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies hierarchy-target non-disclosure before form-request validation.
 */
final readonly class ResolveWorkspaceHierarchyTarget
{
    public function __construct(private WorkspaceHierarchyTargetResolver $targets)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceUid = $request->route('workspaceUid');

        if (!$user instanceof User || !is_string($workspaceUid)) {
            abort(404);
        }

        $this->targets->resolve(ActorId::fromUser($user), $workspaceUid);

        return $next($request);
    }
}
