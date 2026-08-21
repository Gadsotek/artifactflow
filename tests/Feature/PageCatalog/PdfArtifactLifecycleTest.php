<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\PruneOrphanArtifacts;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PdfArtifactLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const string PROCESSOR_SECRET = 'test-pdf-lifecycle-processor-secret-0001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config([
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.shared_secret' => self::PROCESSOR_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_pdf_versions_facts_originals_and_quota_move_recount_and_verify_together(): void
    {
        $this->fakeProcessor(['First extraction', 'Second extraction']);
        $admin = $this->user('PDF Lifecycle Admin', 'pdf-lifecycle-admin@example.test');
        $source = app(CreateSharedWorkspace::class)->handle($admin, 'PDF Lifecycle Source');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'PDF Lifecycle Target');
        $firstPdf = $this->pdf('first');
        $secondPdf = $this->pdf('second');
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $source->uid,
            type: PageType::Pdf,
            title: 'Lifecycle PDF',
            description: null,
            content: $firstPdf,
            sourceFilename: 'lifecycle.pdf',
            source: PageVersionSource::Upload,
        ));
        app(UpdatePageContent::class)->handle($admin, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $secondPdf,
            baseVersionUid: $page->current_version_uid,
            source: PageVersionSource::Upload,
        ));
        $paths = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderBy('version_number')
            ->pluck('content_storage_path')
            ->all();
        $storedBytes = strlen($firstPdf) + strlen($secondPdf);

        app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $page->uid,
            targetWorkspaceUid: $target->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        $this->assertSame($target->uid, $page->refresh()->workspace_uid);
        $this->assertSame(0, Workspace::query()->findOrFail($source->uid)->used_storage_bytes);
        $this->assertSame($storedBytes, Workspace::query()->findOrFail($target->uid)->used_storage_bytes);
        $this->assertSame(2, PdfVersionFact::query()->count());
        foreach ($paths as $path) {
            $this->assertIsString($path);
            Storage::disk('artifacts')->assertExists($path);
        }

        Workspace::query()->whereKey($target->uid)->update(['used_storage_bytes' => 1]);
        $this->assertSame(0, Artisan::call('artifactflow:recount-storage'));
        $this->assertSame($storedBytes, Workspace::query()->findOrFail($target->uid)->used_storage_bytes);

        $this->assertSame(0, Artisan::call('artifactflow:verify-artifacts', [
            '--all' => true,
            '--json' => true,
        ]));
        $this->assertSame([
            'checked' => 2,
            'ok' => 2,
            'missing_file' => 0,
            'hash_mismatch' => 0,
        ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_pdf_pruning_orphan_cleanup_and_hard_delete_remove_the_complete_version_graph(): void
    {
        config(['pages.max_page_versions' => 2]);
        $this->fakeProcessor(['First extraction', 'Second extraction', 'Third extraction']);
        $admin = $this->user('PDF Cleanup Admin', 'pdf-cleanup-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'PDF Cleanup Team');
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Pdf,
            title: 'Cleanup PDF',
            description: null,
            content: $this->pdf('first'),
            sourceFilename: 'cleanup.pdf',
            source: PageVersionSource::Upload,
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($admin, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('second'),
            baseVersionUid: $firstVersion->uid,
            source: PageVersionSource::Upload,
        ));
        app(UpdatePageContent::class)->handle($admin, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $this->pdf('third'),
            baseVersionUid: $secondVersion->uid,
            source: PageVersionSource::Upload,
        ));

        $this->assertNull(PageVersion::query()->find($firstVersion->uid));
        $this->assertNull(PdfVersionFact::query()->find($firstVersion->uid));
        Storage::disk('artifacts')->assertMissing($firstVersion->content_storage_path);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(2, PdfVersionFact::query()->count());

        $orphanPath = 'pages/orphaned-pdf/versions/1-orphan/document.pdf';
        Storage::disk('artifacts')->put($orphanPath, $this->pdf('orphan'));
        touch(Storage::disk('artifacts')->path($orphanPath), Carbon::now()->subDays(2)->getTimestamp());
        $orphanResult = app(PruneOrphanArtifacts::class)->handle(delete: true, minAgeSeconds: 86400);
        $this->assertSame(1, $orphanResult->orphansDeleted);
        Storage::disk('artifacts')->assertMissing($orphanPath);

        $retainedPaths = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->pluck('content_storage_path')
            ->all();
        app(HardDeletePage::class)->handle($admin, new HardDeletePageCommand(
            pageUid: $page->uid,
            confirmation: $page->title,
        ));

        $this->assertNull(Page::query()->find($page->uid));
        $this->assertSame(0, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame(0, PdfVersionFact::query()->count());
        $this->assertSame(0, Workspace::query()->findOrFail($workspace->uid)->used_storage_bytes);
        foreach ($retainedPaths as $path) {
            $this->assertIsString($path);
            Storage::disk('artifacts')->assertMissing($path);
        }
    }

    /** @param list<string> $texts */
    private function fakeProcessor(array $texts): void
    {
        Http::fake(function (Request $request) use (&$texts): \GuzzleHttp\Promise\PromiseInterface {
            $text = array_shift($texts);
            $this->assertIsString($text);
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = json_encode([
                'page_count' => 1,
                'pdf_version' => '1.4',
                'extraction_state' => 'indexed',
                'processor_profile' => 'pdfbox-3.0.8-native-text-v1',
                'text' => $text,
            ], JSON_THROW_ON_ERROR);

            return Http::response($body, 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-ArtifactFlow-Processor-Signature' => PdfProcessorProtocol::responseSignature(
                    $nonce,
                    hash('sha256', $request->body()),
                    $body,
                    self::PROCESSOR_SECRET,
                ),
            ]);
        });
    }

    private function pdf(string $text): string
    {
        return "%PDF-1.4\n{$text}\n%%EOF\n";
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
