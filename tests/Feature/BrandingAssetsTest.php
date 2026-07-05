<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class BrandingAssetsTest extends TestCase
{
    public function test_app_and_marketing_site_publish_the_selected_brand_assets(): void
    {
        $appMark = public_path('brand/artifactflow-mark.svg');
        $appFavicon = public_path('favicon.svg');
        $siteMark = base_path('site/assets/artifactflow-mark.svg');
        $siteFavicon = base_path('site/favicon.svg');

        $this->assertFileExists($appMark);
        $this->assertFileExists($appFavicon);
        $this->assertFileExists($siteMark);
        $this->assertFileExists($siteFavicon);

        $site = file_get_contents(base_path('site/index.html'));

        $this->assertIsString($site);
        $this->assertStringContainsString('assets/artifactflow-mark.svg', $site);
        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="favicon.svg">', $site);
    }
}
