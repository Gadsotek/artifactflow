<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PassageRetrieval\PassageMaintenance;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\InstallationSettings;
use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PassageEvidencePilotTest extends McpTestCase
{
    private const string WORKFLOW = '<html><body><h1>Invoice intake</h1><h2>Email delivery</h2><p>Email delivery uses the shared mailbox that suppliers already use.</p><h2>Dispatch checks</h2><table><tr><th>Field</th><th>On mismatch</th></tr><tr><th>Gross total</th><td>Hold before dispatch</td></tr><tr><th>Tax total</th><td>Hold before dispatch</td></tr></table><h2>Mailbox risk</h2><p>A full mailbox can delay intake.</p></body></html>';
    private const string RULE = "# Receipt release\n\nRelease the receipt after dispatch reconciliation. Keep the receipt on hold during duplicate review.";
    private const array DOCUMENTS = [
        'workflow' => self::WORKFLOW,
        'rule' => self::RULE,
        'receipts' => "# Receipt archive\n\nFile an accepted receipt in the monthly archive.",
        'mail' => "# Dispatch roster\n\nCheck the mailbox roster before changing the dispatch schedule.",
    ];
    // Freeze labels against specific documents before measuring either retrieval path.
    private const array CASES = [
        'rationale' => ['email delivery', 'Why did the team choose electronic mail?', ['workflow' => ['the shared mailbox that suppliers already use']]],
        'mismatch' => ['gross total', 'Where would a discrepancy in the overall amount be caught?', ['workflow' => ['Gross total', 'On mismatch', 'Hold before dispatch']]],
        'exception' => ['receipt release', 'Under what conditions must an otherwise releasable receipt wait?', ['rule' => ['Release the receipt after dispatch reconciliation.', 'on hold during duplicate review']]],
        'risk' => ['mailbox risk', 'What could slow down intake?', ['workflow' => ['A full mailbox can delay intake.']]],
        'two_sources' => ['dispatch', 'How does a failed total check affect sending, and when must a receipt stay on hold?', ['workflow' => ['Gross total', 'On mismatch', 'Hold before dispatch'], 'rule' => ['on hold during duplicate review']]],
        'sender_gap' => ['sender notification', 'What alert does the sender see if a check fails?', []],
    ];

    private string $token;
    /** @var array<string, string> */
    private array $labels = [];
    /** @var array<string, Page> */
    private array $pages = [];

    public function test_synthetic_pilot_checks_source_coordinates_and_measures_both_retrieval_paths(): void
    {
        Storage::fake('artifacts');
        config(['rate_limits.mcp_per_minute' => 500]);
        $owner = $this->createUser('Pilot author', 'pilot-author@example.test');
        $reader = $this->createServiceAccount('Pilot reader', 'pilot-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Pilot');
        $this->addMember($workspace, $reader, WorkspaceRole::Reader);
        $this->token = $this->issueToken($reader, ['mcp:search', 'mcp:read'], workspaceUids: [$workspace->uid])->plainTextToken;
        $other = app(CreateSharedWorkspace::class)->handle($owner, 'Private');
        $this->createPageWithApprovedStatus($owner, $other, 'Private invoice', 'Gross total. Send a private alert.');
        $update = app(UpdatePageContent::class);
        foreach (self::DOCUMENTS as $label => $source) {
            $this->pages[$label] = $this->createPageWithApprovedStatus(
                $owner,
                $workspace,
                'Pilot ' . $label,
                $label === 'rule' ? 'Always release duplicate receipts.' : $source,
                type: $label === 'workflow' ? PageType::HtmlArtifact : PageType::Markdown,
            );
            $this->labels[$this->pages[$label]->uid] = $label;
        }
        $append = function (string $suffix) use ($update, $owner): array {
            $times = [];
            foreach (self::DOCUMENTS as $label => $source) {
                $page = $this->pages[$label];
                $start = hrtime(true);
                $update->handle($owner, new UpdatePageContentCommand($page->uid, $source . $suffix, baseVersionUid: $page->current_version_uid));
                $times[] = (hrtime(true) - $start) / 1e6;
                $page->refresh();
            }

            return $times;
        };
        $off = $append("\n");
        $emptyBytes = $this->indexBytes();
        InstallationSettings::query()->forceCreate(app(InstallationLimitSettings::class)->current()->toPersistenceArray() + [
            'passage_retrieval_enabled' => true, 'passage_retrieval_generation' => 1, 'passage_retrieval_extractor_revision' => 1,
        ]);
        $start = hrtime(true);
        app(PassageMaintenance::class)->backfill(1, 1);
        $backfillMs = (hrtime(true) - $start) / 1e6;
        $this->assertDatabaseCount('passage_sets', 5);
        $on = $append("\n\n");

        $metrics = [];
        foreach (self::CASES as $case => [$keywords, $question, $labels]) {
            foreach (['keywords' => $keywords, 'question' => $question] as $form => $query) {
                $start = hrtime(true);
                $passages = $this->tool('search_passages', ['query' => $query]);
                $ms = (hrtime(true) - $start) / 1e6;
                $evidence = [];
                foreach ($this->payloadList($passages, 'results') as $hit) {
                    $uid = $this->payloadString($hit, 'page_uid');
                    self::assertArrayHasKey($uid, $this->labels);
                    $label = $this->labels[$uid];
                    self::assertSame($this->pages[$label]->current_version_uid, $hit['version_uid']);
                    $text = $this->payloadString($this->payloadArray($hit, 'excerpt'), 'data');
                    $heading = array_map(fn (array $entry): string => $this->payloadString($entry, 'data'), $this->payloadList($hit, 'heading_path'));
                    $this->verifyLocator($hit, self::DOCUMENTS[$label] . "\n\n", $text, $heading);
                    $evidence[] = [$label, $text . ' ' . implode(' ', $heading)];
                }
                $metrics[$case][$form]['passages'] = $this->measure($labels, $evidence, $this->bytes($passages), $ms);
                $start = hrtime(true);
                $search = $this->tool('search', ['query' => $query]);
                $bytes = $this->bytes($search);
                $evidence = [];
                foreach (array_slice($this->payloadList($search, 'results'), 0, 5) as $row) {
                    $uid = $this->payloadString($row, 'uid');
                    self::assertArrayHasKey($uid, $this->labels);
                    $read = $this->tool('read', ['page_uid' => $uid, 'include' => ['content']]);
                    self::assertSame($this->pages[$this->labels[$uid]]->current_version_uid, $read['current_version_uid']);
                    $evidence[] = [$this->labels[$uid], $this->payloadString($this->payloadArray($read, 'content'), 'data')];
                    $bytes += $this->bytes($read);
                }
                $metrics[$case][$form]['search_read'] = $this->measure($labels, $evidence, $bytes, (hrtime(true) - $start) / 1e6);
            }
        }
        $direct = $this->tool('read', ['page_uid' => $this->pages['workflow']->uid, 'include' => ['content']]);
        $report = [
            'cases' => $metrics, 'current_versions' => 5, 'authorized_versions' => 4,
            'passages' => DB::table('passages')->count(), 'index_bytes' => $this->indexBytes(), 'empty_index_bytes' => $emptyBytes,
            'save_off_ms' => $off, 'save_on_ms' => $on, 'backfill_ms' => $backfillMs, 'direct_read_bytes' => $this->bytes($direct),
        ];
        // Only synthetic labels, ranks, timings and byte counts are persisted.
        file_put_contents(storage_path('framework/testing/passage-evidence-pilot.json'), json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function tool(string $name, array $input): array
    {
        return $this->successfulToolPayload($this->callTool($this->token, $name, $input));
    }

    /**
     * @param array<string, mixed> $hit
     * @param list<string> $headings
     */
    private function verifyLocator(array $hit, string $source, string $excerpt, array $headings): void
    {
        $locator = $this->payloadArray($hit, 'source_locator');
        if ($locator['kind'] === 'markdown_source') {
            self::assertIsInt($locator['start_byte']);
            self::assertIsInt($locator['end_byte']);
            self::assertSame($excerpt, substr($source, $locator['start_byte'], $locator['end_byte'] - $locator['start_byte']));

            return;
        }
        self::assertSame('html_source_ranges', $locator['kind']);
        foreach ($this->payloadList($locator, 'ranges') as $range) {
            self::assertIsInt($range['start_byte']);
            self::assertIsInt($range['end_byte']);
            $piece = substr($source, $range['start_byte'], $range['end_byte'] - $range['start_byte']);
            self::assertNotSame('', $piece);
            if ($range['role'] === 'heading') {
                self::assertContains($piece, $headings);
            } else {
                self::assertStringContainsString($piece, $excerpt);
            }
        }
    }

    /**
     * @param array<string, list<string>> $labels
     * @param list<array{string, string}> $evidence
     * @return array{covered: bool|null, first_rank: int|null, hits: int, bytes: int, ms: float}
     */
    private function measure(array $labels, array $evidence, int $bytes, float $ms): array
    {
        $first = null;
        $missing = $labels;
        foreach ($evidence as $rank => [$document, $text]) {
            foreach ($missing[$document] ?? [] as $key => $fact) {
                if (str_contains($text, $fact)) {
                    $first ??= $rank + 1;
                    unset($missing[$document][$key]);
                }
            }
        }

        return ['covered' => $labels === [] ? null : array_filter($missing) === [], 'first_rank' => $first, 'hits' => count($evidence), 'bytes' => $bytes, 'ms' => round($ms, 2)];
    }

    /** @param array<string, mixed> $payload */
    private function bytes(array $payload): int
    {
        return strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function indexBytes(): int
    {
        $value = DB::scalar("SELECT pg_total_relation_size('passage_sets') + pg_total_relation_size('passages') + pg_total_relation_size('passage_index_failures')");
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
