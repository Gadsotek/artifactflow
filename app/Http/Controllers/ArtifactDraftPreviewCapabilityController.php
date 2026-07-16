<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArtifactDraftPreviewCapabilities;
use App\Application\PageCatalog\PageAccess;
use App\Http\Requests\PageCatalog\StoreArtifactDraftPreviewCapabilityRequest;
use Illuminate\Http\JsonResponse;

final class ArtifactDraftPreviewCapabilityController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private readonly ArtifactDraftPreviewCapabilities $capabilities,
        private readonly PageAccess $access,
    ) {
    }

    public function __invoke(StoreArtifactDraftPreviewCapabilityRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $workspaceUid = $request->workspaceUid();
        $this->access->ensureCanCreateInWorkspace($user, $workspaceUid);
        $capability = $this->capabilities->issue(
            $workspaceUid,
            $request->contentBytes(),
            $request->contentSha256(),
        );

        return response()->json([
            'capability' => $capability->token,
            'expires_at' => $capability->expiresAt,
        ]);
    }
}
