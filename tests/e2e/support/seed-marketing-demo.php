<?php

declare(strict_types=1);

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PersonalPageState;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
if (!$app instanceof Application) {
    throw new RuntimeException('The application could not be bootstrapped.');
}
$app->make(Kernel::class)->bootstrap();

// Public screenshots must be populated only in the disposable make e2e database.
$connection = config('database.default');
$database = is_string($connection) ? config('database.connections.' . $connection . '.database') : null;
if (
    !$app->environment('local', 'testing')
    || !is_string($database)
    || !preg_match('/\Aartifactflow_test_e2e_[a-f0-9]{32}\z/', $database)
    || config('database.connections.' . $connection . '.host') !== 'db-test'
) {
    throw new RuntimeException('Marketing fixtures require the isolated make e2e database.');
}
$suffix = $argv[1] ?? '';
if (!preg_match('/\A[a-f0-9]{32}\z/', $suffix)) {
    throw new RuntimeException('A unique fixture run identifier is required.');
}
$createUser = app(CreateUser::class);
$alex = $createUser->handle('Alex Morgan', "alex.{$suffix}@northstar.example", 'af' . $suffix);
$jamie = $createUser->handle('Jamie Chen', "jamie.{$suffix}@northstar.example", 'af' . $suffix);
$sam = $createUser->handle('Sam Rivera', "sam.{$suffix}@northstar.example", 'af' . $suffix);
$workspaces = app(CreateSharedWorkspace::class);
$team = $workspaces->handle($alex, 'Northstar Labs');
$engineering = $workspaces->handle($alex, 'Engineering', $team->uid);
$runbooks = $workspaces->handle($alex, 'Runbooks', $engineering->uid);
$design = $workspaces->handle($alex, 'Product design', $team->uid);
$operations = $workspaces->handle($alex, 'Operations', $team->uid);
foreach ([$jamie, $sam] as $member) {
    WorkspaceMembership::query()->forceCreate([
        'workspace_uid' => $team->uid, 'user_uid' => $member->uid,
        'role' => WorkspaceRole::Editor, 'accepted_at' => now(),
    ]);
}
$categories = [];
foreach (['Getting started', 'Planning tools', 'Engineering guides'] as $name) {
    $categories[$name] = app(CreateCategory::class)->handle($alex, new CreateCategoryCommand($engineering->uid, $name));
}
$fixtures = dirname(__DIR__) . '/fixtures/marketing/';
$incident = file_get_contents($fixtures . 'incident-response.md');
$planner = file_get_contents($fixtures . 'capacity-planner.html');
if (!is_string($incident) || !is_string($planner)) {
    throw new RuntimeException('Marketing fixture content is missing.');
}
$createPage = app(CreatePage::class);
$tool = $createPage->handle($alex, new CreatePageCommand(
    workspaceUid: $engineering->uid,
    type: PageType::HtmlArtifact,
    title: 'Sprint capacity planner',
    description: 'Explore team capacity, focus time, and a sustainable commitment for the next sprint.',
    content: $planner,
    status: PageStatus::Approved,
    categoryUid: $categories['Planning tools']->uid,
    tagNames: ['planning', 'interactive', 'team-tool'],
    changeSummary: 'Initial planning tool.',
));
app(UpdatePageContent::class)->handle($alex, new UpdatePageContentCommand(
    pageUid: $tool->uid,
    content: str_replace('Plan the next two weeks', 'Shape your next sprint', $planner),
    baseVersionUid: $tool->current_version_uid,
    changeSummary: 'Add focus-time guidance and a clear capacity reserve.',
));
$guide = $createPage->handle($jamie, new CreatePageCommand(
    workspaceUid: $engineering->uid,
    type: PageType::Markdown,
    title: 'Incident response guide',
    description: 'A shared playbook for triage, ownership, recovery, and learning after an incident.',
    content: $incident,
    status: PageStatus::Approved,
    categoryUid: $categories['Engineering guides']->uid,
    tagNames: ['runbook', 'operations', 'on-call'],
    changeSummary: 'Initial response flow and ownership checklist.',
));
app(UpdatePageContent::class)->handle($jamie, new UpdatePageContentCommand(
    pageUid: $guide->uid,
    content: $incident . "\n\nKeep handovers brief: impact, actions taken, next decision.\n",
    baseVersionUid: $guide->current_version_uid,
    changeSummary: 'Clarify the handover checklist and recovery verification.',
));
$release = $createPage->handle($sam, new CreatePageCommand(
    workspaceUid: $engineering->uid,
    type: PageType::Markdown,
    title: 'Release checklist',
    description: 'Everything the team checks before a release, with a clear owner for each step.',
    content: "# Release checklist\n\n## Before release\n- [x] Review the changelog\n- [x] Verify migrations in staging\n- [ ] Confirm the recovery plan\n- [ ] Share release notes with the team\n",
    status: PageStatus::Approved,
    categoryUid: $categories['Engineering guides']->uid,
    tagNames: ['release', 'checklist', 'operations'],
));
$createPage->handle($alex, new CreatePageCommand(
    workspaceUid: $engineering->uid,
    type: PageType::Markdown,
    title: 'Developer onboarding',
    description: 'Find the team agreements, essential tools, and first contribution path in one place.',
    content: "# Developer onboarding\n\nWelcome to Northstar Labs. Start with the team workspace, then read the engineering guides and try a small first change.\n",
    status: PageStatus::Approved,
    categoryUid: $categories['Getting started']->uid,
    tagNames: ['onboarding', 'team'],
));
$createPage->handle($jamie, new CreatePageCommand(
    workspaceUid: $engineering->uid,
    type: PageType::Markdown,
    title: 'API design principles',
    description: 'Practical conventions for predictable endpoints, errors, pagination, and versioning.',
    content: "# API design principles\n\nPrefer explicit contracts. Validate at the boundary. Return actionable errors. Keep pagination stable and document changes before rollout.\n",
    status: PageStatus::Draft,
    categoryUid: $categories['Engineering guides']->uid,
    tagNames: ['api', 'architecture'],
));
$createPage->handle($sam, new CreatePageCommand(
    workspaceUid: $design->uid,
    type: PageType::Markdown,
    title: 'Customer research brief',
    description: 'Questions, observations, and decisions for the next round of product discovery.',
    content: "# Customer research brief\n\n## Research question\nHow do teams find the latest version of a useful document?\n\n## Plan\nInterview five fictional teams, compare their workflows, and record what changes our assumptions.\n",
    status: PageStatus::Draft,
    tagNames: ['research', 'product'],
));
$createPage->handle($jamie, new CreatePageCommand(
    workspaceUid: $runbooks->uid,
    type: PageType::Markdown,
    title: 'Service recovery checklist',
    description: 'A short recovery sequence for the on-call engineer.',
    content: $incident,
    status: PageStatus::Approved,
    tagNames: ['runbook', 'recovery'],
));
$createPage->handle($sam, new CreatePageCommand(
    workspaceUid: $operations->uid,
    type: PageType::Markdown,
    title: 'Weekly team update',
    description: 'Progress, upcoming milestones, and decisions that need an owner.',
    content: "# Weekly team update\n\n## Completed\nPublished our incident guide and improved the capacity planner.\n\n## Up next\nReview onboarding feedback and prepare the next release.\n",
    status: PageStatus::Approved,
    tagNames: ['team', 'planning'],
));
$personal = app(PersonalPageState::class);
foreach ([$release, $guide, $tool] as $page) {
    $personal->recordVisit($alex, $page);
    $personal->favorite($alex, $page, true);
}
echo json_encode([
    'email' => $alex->email, 'workspaceUid' => $engineering->uid,
    'toolUid' => $tool->uid, 'guideUid' => $guide->uid, 'releaseUid' => $release->uid,
], JSON_THROW_ON_ERROR);
