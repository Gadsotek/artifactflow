<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

final class SemgrepRequestMassAssignmentFixture
{
    public function persist(object $request): void
    {
        // ruleid: artifactflow.no-request-input-mass-assignment
        Page::query()->create($request->validated());

        // ok: artifactflow.no-request-input-mass-assignment
        Page::query()->create(['title' => $request->title]);
    }
}

final class SemgrepPageFillableFixture
{
    // ruleid: artifactflow.no-sensitive-page-fillable-fields, artifactflow.no-page-version-fillable
    protected $fillable = ['title', 'workspace_uid'];
}

final class SemgrepSafePageFillableFixture
{
    // ruleid: artifactflow.no-page-version-fillable
    protected $fillable = ['title', 'slug'];
}

final class SemgrepPageVersionFixture
{
    // ruleid: artifactflow.no-page-version-fillable
    protected $fillable = ['page_uid'];
}

final class SemgrepSafePageVersionFixture
{
    // ok: artifactflow.no-page-version-fillable
    protected $guarded = ['*'];
}

final class SemgrepWorkspaceFillableFixture
{
    // ruleid: artifactflow.no-sensitive-workspace-fillable-fields, artifactflow.no-page-version-fillable
    protected $fillable = ['name', 'personal_owner_uid'];
}

final class SemgrepSafeWorkspaceFillableFixture
{
    // ruleid: artifactflow.no-page-version-fillable
    protected $fillable = ['name'];
}

// ruleid: artifactflow.no-artifact-sandbox-origin-escape
$unsafeSandbox = 'allow-scripts allow-same-origin';

// ok: artifactflow.no-artifact-sandbox-origin-escape
$safeSandbox = 'allow-scripts';

// ruleid: artifactflow.no-sensitive-url-logging
Log::info('Preview renewed.', ['signed_url' => $signedUrl]);

// ok: artifactflow.no-sensitive-url-logging
Log::info('Preview renewed.', ['artifact_uid' => $artifactUid]);

$unsafeEvent = new DomainEvent(
    // ruleid: artifactflow.no-private-content-in-traceability
    metadata: ['content' => $privateContent],
);

$safeEvent = new DomainEvent(
    // ok: artifactflow.no-private-content-in-traceability
    metadata: ['content_hash' => $contentHash],
);
