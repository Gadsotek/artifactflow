<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonException;
use Tests\TestCase;

final class MarketingSiteSeoTest extends TestCase
{
    /** @var array<string, string> */
    private const array PAGES = [
        'site/index.html' => 'https://artifactflow.app/',
        'site/security/index.html' => 'https://artifactflow.app/security/',
        'site/mcp/index.html' => 'https://artifactflow.app/mcp/',
        'site/self-hosting/index.html' => 'https://artifactflow.app/self-hosting/',
        'site/engineering-harness/index.html' => 'https://artifactflow.app/engineering-harness/',
        'site/roadmap/index.html' => 'https://artifactflow.app/roadmap/',
    ];

    /** @var list<string> */
    private const array SOCIAL_META_PROPERTIES = [
        'og:title',
        'og:description',
        'og:type',
        'og:url',
        'og:site_name',
        'og:image',
        'og:image:alt',
    ];

    /** @var list<string> */
    private const array SOCIAL_META_NAMES = [
        'twitter:card',
        'twitter:title',
        'twitter:description',
        'twitter:image',
        'twitter:image:alt',
    ];

    public function test_every_marketing_page_has_unique_complete_search_and_social_metadata(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (self::PAGES as $relativePath => $canonicalUrl) {
            $path = base_path($relativePath);
            $this->assertFileExists($path);

            $xpath = $this->htmlXPath($path);

            $this->assertSame('en', $this->singleAttribute($xpath, '/html', 'lang', $relativePath));
            $headings = $xpath->query('//h1');
            $this->assertNotFalse($headings);
            $this->assertSame(1, $headings->length, sprintf('%s must contain exactly one H1.', $relativePath));

            $title = $this->singleNodeValue($xpath, '//title', $relativePath);
            $description = $this->singleAttribute(
                $xpath,
                '//meta[@name="description"]',
                'content',
                $relativePath,
            );

            $this->assertNotSame('', $title);
            $this->assertNotSame('', $description);
            $this->assertNotContains($title, $titles, sprintf('%s must have a unique title.', $relativePath));
            $this->assertNotContains(
                $description,
                $descriptions,
                sprintf('%s must have a unique meta description.', $relativePath),
            );

            $titles[] = $title;
            $descriptions[] = $description;

            $this->assertSame(
                $canonicalUrl,
                $this->singleAttribute($xpath, '//link[@rel="canonical"]', 'href', $relativePath),
            );
            $this->assertSame(
                $canonicalUrl,
                $this->singleAttribute($xpath, '//meta[@property="og:url"]', 'content', $relativePath),
            );

            foreach (self::SOCIAL_META_PROPERTIES as $property) {
                $this->assertNotSame(
                    '',
                    $this->singleAttribute(
                        $xpath,
                        sprintf('//meta[@property="%s"]', $property),
                        'content',
                        $relativePath,
                    ),
                );
            }

            foreach (self::SOCIAL_META_NAMES as $name) {
                $this->assertNotSame(
                    '',
                    $this->singleAttribute(
                        $xpath,
                        sprintf('//meta[@name="%s"]', $name),
                        'content',
                        $relativePath,
                    ),
                );
            }

            $this->assertSame(
                'summary_large_image',
                $this->singleAttribute($xpath, '//meta[@name="twitter:card"]', 'content', $relativePath),
            );
        }

        $this->assertCount(count(self::PAGES), array_unique($titles));
        $this->assertCount(count(self::PAGES), array_unique($descriptions));
    }

    /**
     * @throws JsonException
     */
    public function test_json_ld_is_valid_and_uses_only_truthful_expected_entity_types(): void
    {
        $types = [];

        foreach (self::PAGES as $relativePath => $canonicalUrl) {
            $xpath = $this->htmlXPath(base_path($relativePath));
            $scripts = $xpath->query('//script[@type="application/ld+json"]');

            $this->assertNotFalse($scripts);
            $this->assertGreaterThanOrEqual(1, $scripts->length, sprintf('%s must declare JSON-LD.', $relativePath));

            foreach ($scripts as $script) {
                $this->assertInstanceOf(DOMElement::class, $script);
                $decoded = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);

                $this->assertIsArray($decoded);
                $this->assertSame('https://schema.org', $decoded['@context'] ?? null);
                $this->assertStringContainsString(
                    $canonicalUrl,
                    $script->textContent,
                    sprintf('%s JSON-LD must identify its canonical page.', $relativePath),
                );

                $this->collectJsonLdTypes($decoded, $types);
            }
        }

        $this->assertContains('WebSite', $types);
        $this->assertContains('Organization', $types);
        $this->assertContains('WebPage', $types);
        $this->assertSame([], array_values(array_diff(array_unique($types), ['WebSite', 'Organization', 'WebPage'])));
    }

    public function test_sitemap_contains_each_canonical_marketing_url_exactly_once(): void
    {
        $path = base_path('site/sitemap.xml');
        $this->assertFileExists($path);

        $document = new DOMDocument();
        $this->assertTrue($document->load($path));

        $urls = [];

        foreach ($document->getElementsByTagName('loc') as $location) {
            $this->assertInstanceOf(DOMElement::class, $location);
            $urls[] = trim($location->textContent);
        }

        $expectedUrls = array_values(self::PAGES);
        sort($expectedUrls);
        sort($urls);

        $this->assertSame($expectedUrls, $urls);
        $this->assertCount(count(array_unique($urls)), $urls, 'Every sitemap URL must appear exactly once.');
        $this->assertStringNotContainsString('<lastmod>', $document->saveXML() ?: '');
    }

    public function test_marketing_robots_policy_permits_indexing_without_deciding_training_crawler_policy(): void
    {
        $path = base_path('site/robots.txt');
        $this->assertFileExists($path);

        $robots = file_get_contents($path);
        $this->assertIsString($robots);
        $this->assertStringContainsString("User-agent: *\nAllow: /", $robots);
        $this->assertStringContainsString('User-agent: OAI-SearchBot', $robots);
        $this->assertStringContainsString('User-agent: Claude-SearchBot', $robots);
        $this->assertStringContainsString('User-agent: Claude-User', $robots);
        $this->assertStringContainsString('Sitemap: https://artifactflow.app/sitemap.xml', $robots);
        $this->assertStringNotContainsString('GPTBot', $robots);
        $this->assertStringNotContainsString("User-agent: ClaudeBot\n", $robots);
    }

    public function test_root_relative_marketing_links_resolve_to_static_pages_or_assets(): void
    {
        foreach (array_keys(self::PAGES) as $relativePath) {
            $xpath = $this->htmlXPath(base_path($relativePath));
            $attributes = $xpath->query('//@href | //@src');

            $this->assertNotFalse($attributes);

            foreach ($attributes as $attribute) {
                $this->assertIsString($attribute->nodeValue);
                $value = trim($attribute->nodeValue);

                if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
                    continue;
                }

                $target = parse_url($value, PHP_URL_PATH);
                $this->assertIsString($target);

                $resolved = str_ends_with($target, '/')
                    ? base_path(sprintf('site%sindex.html', $target))
                    : base_path(sprintf('site%s', $target));

                $this->assertFileExists(
                    $resolved,
                    sprintf('%s links to missing static target %s.', $relativePath, $value),
                );
            }
        }
    }

    public function test_every_page_loads_early_theme_control_and_only_the_gallery_loads_deferred_gallery_code(): void
    {
        foreach (array_keys(self::PAGES) as $relativePath) {
            $xpath = $this->htmlXPath(base_path($relativePath));
            $this->assertSame(
                '/assets/site.css?v=20260722-2',
                $this->singleAttribute($xpath, '//link[@rel="stylesheet"]', 'href', $relativePath),
            );

            $this->assertSame(
                '/assets/theme.js?v=20260722-2',
                $this->singleAttribute($xpath, '//head/script[@data-theme-bootstrap]', 'src', $relativePath),
            );

            $toggles = $xpath->query('//button[@data-theme-toggle]');
            $lightMarks = $xpath->query('//header//img[@src="/assets/artifactflow-mark.svg"]');
            $darkMarks = $xpath->query('//header//img[@src="/assets/artifactflow-mark-light.svg"]');
            $this->assertNotFalse($toggles);
            $this->assertNotFalse($lightMarks);
            $this->assertNotFalse($darkMarks);
            $this->assertSame(1, $toggles->length);
            $this->assertSame(1, $lightMarks->length);
            $this->assertSame(1, $darkMarks->length);

            $scripts = $xpath->query('//script[@src]');
            $this->assertNotFalse($scripts);

            if ($relativePath === 'site/index.html') {
                $this->assertSame(2, $scripts->length);
                $this->assertSame(
                    'defer',
                    $this->singleAttribute(
                        $xpath,
                        '//script[@src="/assets/site.js?v=20260722-2"]',
                        'defer',
                        $relativePath,
                    ),
                );

                continue;
            }

            $this->assertSame(1, $scripts->length, sprintf('%s only needs the theme control.', $relativePath));
        }
    }

    public function test_theme_control_persists_an_explicit_choice_and_keeps_system_fallbacks(): void
    {
        $script = file_get_contents(base_path('site/assets/theme.js'));
        $css = file_get_contents(base_path('site/assets/site.css'));

        $this->assertIsString($script);
        $this->assertIsString($css);
        $this->assertStringContainsString("'artifactflow-theme'", $script);
        $this->assertStringContainsString('localStorage.getItem', $script);
        $this->assertStringContainsString('localStorage.setItem', $script);
        $this->assertStringContainsString("matchMedia('(prefers-color-scheme: dark)')", $script);
        $this->assertStringContainsString('dataset.theme', $script);
        $this->assertStringContainsString(':root[data-theme="dark"]', $css);
        $this->assertStringContainsString(':root:not([data-theme="light"])', $css);
        $this->assertStringContainsString('.brand-mark-on-dark', $css);
    }

    public function test_shared_styles_follow_the_system_dark_mode_without_client_side_theming(): void
    {
        $css = file_get_contents(base_path('site/assets/site.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        $this->assertStringContainsString('color-scheme: dark', $css);
        $this->assertStringContainsString('--paper: #0d0f13', $css);
        $this->assertStringNotContainsString('localStorage', $css);
    }

    public function test_homepage_publishes_responsive_modern_screenshot_sources(): void
    {
        foreach (['app-dashboard', 'app-artifact-live', 'app-markdown'] as $screenshot) {
            foreach ([800, 1200, 1600] as $width) {
                $this->assertFileExists(base_path(sprintf('site/assets/%s-%d.avif', $screenshot, $width)));
            }

            foreach ([800, 1200] as $width) {
                $this->assertFileExists(base_path(sprintf('site/assets/%s-%d.jpg', $screenshot, $width)));
            }

            $this->assertFileExists(base_path(sprintf('site/assets/%s.jpg', $screenshot)));
        }

        $xpath = $this->htmlXPath(base_path('site/index.html'));
        $modernSources = $xpath->query('//picture/source[@type="image/avif"]');
        $prioritizedImages = $xpath->query('//img[@fetchpriority="high"]');
        $lazyImages = $xpath->query('//img[@loading="lazy"]');

        $this->assertNotFalse($modernSources);
        $this->assertNotFalse($prioritizedImages);
        $this->assertNotFalse($lazyImages);
        $this->assertGreaterThanOrEqual(3, $modernSources->length);
        $this->assertSame(1, $prioritizedImages->length);
        $this->assertGreaterThanOrEqual(3, $lazyImages->length);
    }

    public function test_social_preview_has_the_recommended_landscape_dimensions(): void
    {
        $path = base_path('site/assets/artifactflow-social.png');
        $this->assertFileExists($path);

        $dimensions = getimagesize($path);
        $this->assertIsArray($dimensions);
        $this->assertSame(1200, $dimensions[0]);
        $this->assertSame(630, $dimensions[1]);
    }

    public function test_public_marketing_pages_do_not_use_em_dashes(): void
    {
        foreach (array_keys(self::PAGES) as $relativePath) {
            $html = file_get_contents(base_path($relativePath));

            $this->assertIsString($html);
            $this->assertStringNotContainsString('—', $html, sprintf('%s contains an em dash.', $relativePath));
        }
    }

    public function test_public_copy_preserves_the_documented_network_residuals(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));
        $readme = file_get_contents(base_path('README.md'));
        $roadmap = file_get_contents(base_path('ROADMAP.md'));

        $this->assertIsString($homepage);
        $this->assertIsString($readme);
        $this->assertIsString($roadmap);
        $this->assertStringNotContainsString('network access from artifacts is blocked by design', $homepage);
        $this->assertStringContainsString('documented self-navigation and browser-dependent WebRTC residuals', $homepage);
        $this->assertStringContainsString('Script-initiated top-level navigation cannot be fully prevented', $readme);
        $this->assertStringContainsString('WebRTC blocking is browser-dependent', $readme);
        $this->assertStringContainsString('documented self-navigation and browser-dependent WebRTC residuals', $roadmap);
        $this->assertStringContainsString('OS or container boundary denies outbound network access', $roadmap);
    }

    public function test_public_copy_distinguishes_guarantees_from_advisory_safeguards(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));
        $security = file_get_contents(base_path('site/security/index.html'));
        $mcp = file_get_contents(base_path('site/mcp/index.html'));

        $this->assertIsString($homepage);
        $this->assertIsString($security);
        $this->assertIsString($mcp);
        $this->assertStringContainsString('Source diffs exist; a visual diff UI does not yet.', $homepage);
        $this->assertStringContainsString('bypassable by obfuscation', $security);
        $this->assertStringContainsString('a clean scan is not proof that no secret was stored', $security);
        $this->assertStringContainsString('Write safeguards', $mcp);
        $this->assertStringNotContainsString('Write guarantees', $mcp);
    }

    public function test_roadmap_page_separates_current_work_from_candidates_and_non_promises(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));
        $roadmapPage = file_get_contents(base_path('site/roadmap/index.html'));
        $llms = file_get_contents(base_path('site/llms.txt'));

        $this->assertIsString($homepage);
        $this->assertIsString($roadmapPage);
        $this->assertIsString($llms);
        $this->assertStringContainsString('href="/roadmap/"', $homepage);
        $this->assertStringContainsString('Direction, not a release promise.', $roadmapPage);
        $this->assertStringContainsString('Available now', $roadmapPage);
        $this->assertStringContainsString('Post-alpha candidate', $roadmapPage);
        $this->assertStringContainsString('Beta candidates', $roadmapPage);
        $this->assertStringContainsString('Explicitly not promised', $roadmapPage);
        $this->assertStringContainsString(
            'https://github.com/Gadsotek/artifactflow/blob/main/ROADMAP.md',
            $roadmapPage,
        );
        $this->assertStringContainsString('https://artifactflow.app/roadmap/', $llms);
    }

    private function htmlXPath(string $path): DOMXPath
    {
        $html = file_get_contents($path);
        $this->assertIsString($html);

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, sprintf('%s must be parseable HTML.', $path));

        return new DOMXPath($document);
    }

    private function singleNodeValue(DOMXPath $xpath, string $query, string $relativePath): string
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, sprintf('%s must contain exactly one %s.', $relativePath, $query));

        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return trim($node->textContent);
    }

    private function singleAttribute(DOMXPath $xpath, string $query, string $attribute, string $relativePath): string
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, sprintf('%s must contain exactly one %s.', $relativePath, $query));

        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);
        $this->assertTrue($node->hasAttribute($attribute), sprintf('%s is missing %s.', $query, $attribute));

        return trim($node->getAttribute($attribute));
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $types
     */
    private function collectJsonLdTypes(array $value, array &$types): void
    {
        foreach ($value as $key => $item) {
            if ($key === '@type') {
                $this->assertIsString($item);
                $types[] = $item;

                continue;
            }

            if (is_array($item)) {
                $this->collectJsonLdTypes($item, $types);
            }
        }
    }
}
