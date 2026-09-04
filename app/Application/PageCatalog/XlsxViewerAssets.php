<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Facades\Vite;
use LogicException;

final readonly class XlsxViewerAssets
{
    /**
     * @return array{script: string, stylesheet: string}
     */
    public function paths(): array
    {
        return [
            'script' => $this->localPath(Vite::asset('resources/js/xlsx-viewer.js')),
            'stylesheet' => $this->localPath(Vite::asset('resources/css/xlsx-viewer.css')),
        ];
    }

    private function localPath(string $assetUrl): string
    {
        $path = parse_url($assetUrl, PHP_URL_PATH);

        if (!is_string($path) || !str_starts_with($path, '/build/assets/')) {
            throw new LogicException('XLSX viewer assets must resolve to local production build paths.');
        }

        return $path;
    }
}
