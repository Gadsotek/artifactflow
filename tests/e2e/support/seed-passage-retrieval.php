<?php

declare(strict_types=1);

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
if (!$app instanceof Application) {
    throw new RuntimeException('Application bootstrap failed.');
}
$app->make(Kernel::class)->bootstrap();
$connection = config()->string('database.default');
$database = config()->string('database.connections.' . $connection . '.database');
$suffix = $argv[2] ?? '';
if (!$app->environment('local', 'testing') || preg_match('/^artifactflow_test_e2e_[a-f0-9]{32}$/D', $database) !== 1
    || config('database.connections.' . $connection . '.host') !== 'db-test' || preg_match('/^[a-f0-9]{32}$/D', $suffix) !== 1) {
    throw new RuntimeException('Passage fixtures require the isolated browser test database.');
}

$email = 'passage-admin-' . $suffix . '@example.test';
$name = 'Passage fixture ' . $suffix;
$action = $argv[1] ?? '';
if ($action === 'accounts') {
    $admin = app(CreateUser::class)->handle('Passage administrator', $email, 'Af' . $suffix);
    $reader = app(CreateUser::class)->handle('Passage reader', 'passage-reader-' . $suffix . '@example.test', 'Af' . $suffix);
    $admin->forceFill([
        'is_system_admin' => true,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => [Hash::make(strtoupper(substr($suffix, 0, 10))), Hash::make(strtoupper(substr($suffix, 10, 10)))],
    ])->save();
    $workspace = app(CreateSharedWorkspace::class)->handle($admin, $name);
    WorkspaceMembership::query()->forceCreate([
        'user_uid' => $reader->uid, 'workspace_uid' => $workspace->uid, 'role' => WorkspaceRole::Reader, 'accepted_at' => now(),
    ]);

    return;
}

$admin = User::query()->where('email', $email)->sole();
$workspace = Workspace::query()->where('name', $name)->sole();
if ($action === 'create') {
    $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
        workspaceUid: $workspace->uid,
        type: PageType::Markdown,
        title: 'Invoice guide',
        description: null,
        content: 'Reconcile the invoice before payment.',
    ));
    // Keep the fixture credential in this process. The browser receives only the
    // revision URL returned by the normal authenticated MCP endpoint.
    $token = app(McpAccessTokenIssuer::class)->issue($admin, 'Passage fixture', ['mcp:search', 'mcp:read'], now()->addMinutes(5), workspaceUids: [$workspace->uid]);
    $request = Request::create(config()->string('app.url') . '/mcp', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json, text/event-stream',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token->plainTextToken,
        'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
        'HTTP_MCP_METHOD' => 'tools/call',
        'HTTP_MCP_NAME' => 'search_passages',
    ], content: json_encode([
        'jsonrpc' => '2.0', 'id' => 'passage-citation', 'method' => 'tools/call',
        'params' => [
            'name' => 'search_passages', 'arguments' => ['query' => 'reconcile invoice', 'page_uid' => $page->uid],
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []],
        ],
    ], JSON_THROW_ON_ERROR));
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $envelope = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $text = data_get($envelope, 'result.content.0.text');
    $hits = is_string($text) ? json_decode($text, true, flags: JSON_THROW_ON_ERROR) : null;
    $citation = data_get($hits, 'results.0.citation_url');
    if (!is_string($citation) || data_get($hits, 'results.0.version_uid') !== $page->current_version_uid) {
        throw new RuntimeException('The passage tool did not return the expected revision.');
    }
    echo 'PASSAGE_CITATION=' . $citation . PHP_EOL;
} elseif ($action === 'edit') {
    $page = Page::query()->where('workspace_uid', $workspace->uid)->where('title', 'Invoice guide')->sole();
    app(UpdatePageContent::class)->handle($admin, new UpdatePageContentCommand(
        $page->uid,
        'Route the invoice for approval.',
        baseVersionUid: $page->current_version_uid,
    ));
} else {
    throw new RuntimeException('Unknown passage fixture action.');
}
