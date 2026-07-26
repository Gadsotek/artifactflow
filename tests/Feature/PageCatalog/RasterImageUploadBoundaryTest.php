<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\FakesImageParser;
use Tests\TestCase;

final class RasterImageUploadBoundaryTest extends TestCase
{
    use FakesImageParser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageParser();
    }

    public function test_oversized_image_upload_is_rejected_before_its_content_is_read(): void
    {
        Storage::fake('artifacts');
        config(['pages.max_image_bytes' => 1]);

        $editor = User::query()->create([
            'name' => 'Oversized Screenshot Editor',
            'email' => 'oversized-screenshot@example.test',
            'password' => Hash::make('password'),
        ]);
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Oversized Images');
        $path = tempnam(sys_get_temp_dir(), 'artifactflow-oversized-image-');
        $this->assertIsString($path);
        $this->assertSame(8192, file_put_contents($path, str_repeat('x', 8192)));
        $upload = new class($path) extends UploadedFile {
            public function __construct(string $path)
            {
                parent::__construct($path, 'oversized.png', 'image/png', null, true);
            }

            public function getContent(): string
            {
                throw new RuntimeException('Oversized upload content must not be read.');
            }
        };

        $this->actingAs($editor)
            ->from('/pages/create')
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'Oversized Screenshot',
                'status' => 'draft',
                'image_file' => $upload,
            ])
            ->assertRedirect('/pages/create')
            ->assertSessionHasErrors('image_file');

        $this->assertSame(0, Page::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_valid_image_upload_content_is_read_only_once(): void
    {
        Storage::fake('artifacts');

        $editor = User::query()->create([
            'name' => 'Single Read Screenshot Editor',
            'email' => 'single-read-screenshot@example.test',
            'password' => Hash::make('password'),
        ]);
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Single Read Images');
        $path = tempnam(sys_get_temp_dir(), 'artifactflow-single-read-image-');
        $this->assertIsString($path);
        $this->assertIsInt(file_put_contents($path, $this->png()));
        $upload = new class($path) extends UploadedFile {
            public int $readCount = 0;

            public function __construct(string $path)
            {
                parent::__construct($path, 'single-read.png', 'image/png', null, true);
            }

            public function getContent(): string
            {
                $this->readCount++;

                return parent::getContent();
            }
        };

        $this->actingAs($editor)
            ->post('/pages', [
                'workspace_uid' => $workspace->uid,
                'type' => 'image',
                'mode' => 'image_upload',
                'title' => 'Single Read Screenshot',
                'status' => 'draft',
                'image_file' => $upload,
            ])
            ->assertRedirect();

        $this->assertSame(1, $upload->readCount);
    }

    private function png(): string
    {
        $compressed = gzcompress("\x00\x23\x78\xdd\xff");
        $this->assertIsString($compressed);

        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
            . $this->pngChunk('IDAT', $compressed)
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }
}
