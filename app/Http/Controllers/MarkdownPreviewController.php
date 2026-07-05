<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\PreviewMarkdown;
use App\Application\PageCatalog\PreviewMarkdownCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\PageCatalog\PreviewMarkdownRequest;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class MarkdownPreviewController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function __invoke(PreviewMarkdownRequest $request, Page $page, PreviewMarkdown $previewMarkdown): JsonResponse
    {
        try {
            $html = $previewMarkdown->handle(
                actor: $this->authenticatedUser($request),
                command: new PreviewMarkdownCommand(
                    pageUid: $page->uid,
                    content: $request->content(),
                ),
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'content' => $exception->getMessage(),
            ]);
        }

        return response()->json(['html' => $html]);
    }
}
