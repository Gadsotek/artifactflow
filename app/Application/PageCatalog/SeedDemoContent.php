<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;

final readonly class SeedDemoContent
{
    public function __construct(
        private CreatePage $createPage,
    ) {
    }

    /**
     * @return list<Page>
     */
    public function handle(User $user): array
    {
        $userUid = $user->getKey();

        if (!is_string($userUid) || $userUid === '') {
            throw new DomainRuleViolation('Demo content can only be seeded for a saved user.');
        }

        $workspace = Workspace::query()
            ->where('personal_owner_uid', $userUid)
            ->first();

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Demo content requires the user to have a personal workspace.');
        }

        return [
            $this->seedMarkdownPage($user, $workspace),
            $this->seedHtmlArtifactPage($user, $workspace),
        ];
    }

    private function seedMarkdownPage(User $user, Workspace $workspace): Page
    {
        $existingPage = $this->existingPage($workspace, 'Hello World Markdown');

        if ($existingPage instanceof Page) {
            return $existingPage;
        }

        return $this->createPage->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Hello World Markdown',
            description: 'Starter Markdown page with a Mermaid diagram.',
            content: <<<'MARKDOWN'
# Hello World Markdown

This page proves Markdown source, internal links, and Mermaid diagrams can be stored safely.

[[Hello World HTML Artifact]]

```mermaid
graph TD
    User[User] --> App[artifactflow]
    App --> Knowledge[Executable knowledge base]
```
MARKDOWN,
            tagNames: ['demo', 'hello-world', 'mermaid'],
        ));
    }

    private function seedHtmlArtifactPage(User $user, Workspace $workspace): Page
    {
        $existingPage = $this->existingPage($workspace, 'Hello World HTML Artifact');

        if ($existingPage instanceof Page) {
            return $existingPage;
        }

        return $this->createPage->handle($user, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Hello World HTML Artifact',
            description: 'Starter single-file HTML artifact with isolated JavaScript.',
            content: <<<'HTML'
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Hello HTML Artifact</title>
        <style>
            body { font-family: system-ui, sans-serif; margin: 2rem; }
            output { display: block; margin-top: 1rem; font-weight: 700; }
        </style>
    </head>
    <body>
        <h1>Hello HTML Artifact</h1>
        <button type="button" id="run">Run artifact script</button>
        <output id="result">Waiting</output>
        <script>
            document.getElementById('run').addEventListener('click', () => {
                document.getElementById('result').textContent = 'JavaScript executed inside the artifact.';
            });
        </script>
    </body>
</html>
HTML,
            tagNames: ['demo', 'hello-world', 'html-artifact'],
            sourceFilename: 'hello-world.html',
        ));
    }

    private function existingPage(Workspace $workspace, string $title): ?Page
    {
        $page = Page::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('title', $title)
            ->first();

        return $page instanceof Page ? $page : null;
    }
}
