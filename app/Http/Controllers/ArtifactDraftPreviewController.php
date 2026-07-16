<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\PageCatalog\ArtifactDraftPreviewCapabilities;
use App\Http\Support\ArtifactSandboxResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * Renders unsaved HTML draft content on the isolated artifact origin, using the
 * exact same hardened sandbox response as a saved artifact. The pre-save preview
 * must be a true match for the saved artifact, so it deliberately does not use a
 * `srcdoc` iframe on the app origin (which would inherit the app CSP and drop the
 * artifact's inline styles). Stateless: it echoes the posted content back
 * hardened and never persists anything.
 */
final class ArtifactDraftPreviewController
{
    public function __construct(
        private ArtifactSandboxResponder $responder,
        private InstallationLimitSettings $limits,
        private ArtifactDraftPreviewCapabilities $capabilities,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $capability = $request->input('capability');
        $contentUpload = $request->file('content');

        if (!is_string($capability) || !$this->capabilities->hasValidEnvelope($capability)) {
            abort(404);
        }

        if (!$contentUpload instanceof UploadedFile || !$contentUpload->isValid()) {
            abort(404);
        }

        try {
            $content = $contentUpload->getContent();
        } catch (FileException) {
            abort(404);
        }

        if (
            !$this->capabilities->matches($capability, $content)
        ) {
            abort(404);
        }

        // A valid bearer capability still serves only an explicit iframe embed,
        // so an absent/`document` Sec-Fetch-Dest cannot turn it into a top-level page.
        if (!$this->responder->isEmbeddedIframeRequest($request)) {
            Log::warning('artifact_draft_preview.rejected', ['reason' => 'not_iframe_embed']);

            return $this->responder->topLevelNavigationNotice($this->openInAppUrl());
        }

        if (trim($content) === '') {
            return $this->rejectInvalid('empty_content', 'Add HTML content before previewing.');
        }

        if (strlen($content) > $this->limits->integer('pages.max_html_bytes')) {
            return $this->rejectInvalid('content_too_large', 'This HTML draft is too large to preview.');
        }

        Log::info('artifact_draft_preview.served', ['bytes' => strlen($content)]);

        return $this->responder->document($content);
    }

    private function rejectInvalid(string $reason, string $message): Response
    {
        Log::warning('artifact_draft_preview.rejected', ['reason' => $reason]);

        return $this->responder->document(
            '<!doctype html><p style="font-family:system-ui,-apple-system,sans-serif">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
                . '</p>',
        )->setStatusCode(422);
    }

    private function openInAppUrl(): ?string
    {
        // Build the recovery link from the app origin, not this artifact host's own app.url
        // (which compose sets to the artifact origin), so "Open it inside ArtifactFlow" lands
        // on the app rather than 404ing back here.
        $appOrigin = rtrim($this->responder->appOrigin(), '/');

        return $appOrigin === '' ? null : $appOrigin . '/pages/create';
    }
}
