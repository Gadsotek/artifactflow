<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonException;
use Tests\TestCase;

final class PublicSiteSeoTest extends TestCase
{
    /** @var array<string, string> */
    private const array PAGES = [
        'site/index.html' => 'https://artifactflow.app/',
        'site/security/index.html' => 'https://artifactflow.app/security/',
        'site/mcp/index.html' => 'https://artifactflow.app/mcp/',
        'site/self-hosting/index.html' => 'https://artifactflow.app/self-hosting/',
        'site/engineering-harness/index.html' => 'https://artifactflow.app/engineering-harness/',
        'site/workflow/index.html' => 'https://artifactflow.app/workflow/',
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

    public function test_every_public_page_has_unique_complete_search_and_social_metadata(): void
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

    public function test_every_public_page_keeps_the_engineering_harness_second_in_main_navigation(): void
    {
        $expectedLinks = [
            ['Product', '/#product'],
            ['Harness', '/engineering-harness/'],
            ['Safety', '/security/'],
            ['MCP', '/mcp/'],
            ['Self-host', '/self-hosting/'],
            ['Roadmap', '/roadmap/'],
            ['GitHub ↗', 'https://github.com/Gadsotek/artifactflow'],
        ];

        foreach (array_keys(self::PAGES) as $relativePath) {
            $xpath = $this->htmlXPath(base_path($relativePath));
            $links = $xpath->query('//nav[@aria-label="Main navigation"]/a');

            $this->assertNotFalse($links);
            $this->assertSame(count($expectedLinks), $links->length, $relativePath);

            foreach ($expectedLinks as $index => [$label, $href]) {
                $link = $links->item($index);

                $this->assertInstanceOf(DOMElement::class, $link);
                $this->assertSame($label, trim($link->textContent), $relativePath);
                $this->assertSame($href, $link->getAttribute('href'), $relativePath);
            }
        }
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

    public function test_sitemap_contains_each_canonical_public_url_exactly_once(): void
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

    public function test_public_robots_policy_permits_indexing_without_deciding_training_crawler_policy(): void
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

    public function test_root_relative_public_links_resolve_to_static_pages_or_assets(): void
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

    public function test_every_page_loads_early_theme_control_and_only_interactive_pages_load_deferred_site_code(): void
    {
        foreach (array_keys(self::PAGES) as $relativePath) {
            $xpath = $this->htmlXPath(base_path($relativePath));
            $this->assertSame(
                '/assets/site.css?v=20260723-3',
                $this->singleAttribute($xpath, '//link[@rel="stylesheet"]', 'href', $relativePath),
            );

            $this->assertSame(
                '/assets/theme.js?v=20260722-2',
                $this->singleAttribute($xpath, '//head/script[@data-theme-bootstrap]', 'src', $relativePath),
            );

            $toggles = $xpath->query('//button[@data-theme-toggle]');
            $lightMarks = $xpath->query('//header//img[@src="/assets/artifactflow-mark.svg"]');
            $darkMarks = $xpath->query('//header//img[@src="/assets/artifactflow-mark-light.svg"]');
            $themeIcons = $xpath->query('//button[@data-theme-toggle]//*[local-name()="svg"]');
            $this->assertNotFalse($toggles);
            $this->assertNotFalse($lightMarks);
            $this->assertNotFalse($darkMarks);
            $this->assertNotFalse($themeIcons);
            $this->assertSame(1, $toggles->length);
            $this->assertSame(1, $lightMarks->length);
            $this->assertSame(1, $darkMarks->length);
            $this->assertSame(2, $themeIcons->length, sprintf('%s must use two aligned SVG theme icons.', $relativePath));

            $scripts = $xpath->query('//script[@src]');
            $this->assertNotFalse($scripts);

            if (in_array($relativePath, ['site/index.html', 'site/mcp/index.html'], true)) {
                $this->assertSame(2, $scripts->length);
                $this->assertSame(
                    'defer',
                    $this->singleAttribute(
                        $xpath,
                        '//script[@src="/assets/site.js?v=20260723-1"]',
                        'defer',
                        $relativePath,
                    ),
                );

                continue;
            }

            $this->assertSame(1, $scripts->length, sprintf('%s only needs the theme control.', $relativePath));
        }
    }

    public function test_every_public_image_declares_intrinsic_dimensions(): void
    {
        foreach (array_keys(self::PAGES) as $relativePath) {
            $xpath = $this->htmlXPath(base_path($relativePath));
            $images = $xpath->query('//img');

            $this->assertNotFalse($images);

            foreach ($images as $image) {
                $this->assertInstanceOf(DOMElement::class, $image);
                $this->assertTrue($image->hasAttribute('width'), sprintf('%s has an image without width.', $relativePath));
                $this->assertTrue($image->hasAttribute('height'), sprintf('%s has an image without height.', $relativePath));
            }
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
        $this->assertStringContainsString('--theme-toggle-sun: #fff;', $css);
        $this->assertStringContainsString('--theme-toggle-moon: #000;', $css);
        $this->assertStringContainsString('color: var(--theme-toggle-sun);', $css);
        $this->assertStringContainsString('color: var(--theme-toggle-moon);', $css);
        $this->assertSame(2, substr_count($css, '--theme-toggle-moon: #fff;'));
    }

    public function test_theme_control_uses_a_touch_friendly_target(): void
    {
        $css = file_get_contents(base_path('site/assets/site.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.theme-toggle\s*\{[^}]*width:\s*4\.5rem;[^}]*height:\s*2\.75rem;[^}]*\}/s',
            $css,
        );
    }

    public function test_static_site_javascript_is_in_the_repository_lint_and_format_gates(): void
    {
        $package = file_get_contents(base_path('package.json'));
        $eslint = file_get_contents(base_path('eslint.config.js'));

        $this->assertIsString($package);
        $this->assertIsString($eslint);
        $this->assertSame(3, substr_count($package, 'site/assets/*.js'));
        $this->assertStringContainsString("'site/assets/*.js'", $eslint);
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

    public function test_gallery_intro_keeps_readable_distance_from_the_section_edge(): void
    {
        $css = file_get_contents(base_path('site/assets/site.css'));

        $this->assertIsString($css);
        $this->assertStringNotContainsString(".gallery {\n  padding-top: 0;", $css);
    }

    public function test_safety_cards_keep_dark_surfaces_when_the_site_uses_the_light_theme(): void
    {
        $css = file_get_contents(base_path('site/assets/site.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.safety \.split-card\s*\{[^}]*color:\s*var\(--white\);[^}]*border-color:\s*rgba\(255, 253, 248, 0\.28\);[^}]*background:\s*rgba\(255, 255, 255, 0\.05\);[^}]*\}/s',
            $css,
        );
    }

    public function test_harness_describes_approval_hooks_as_bypassable_policy(): void
    {
        $harness = file_get_contents(base_path('site/engineering-harness/index.html'));

        $this->assertIsString($harness);
        $this->assertStringContainsString(
            '<h2>Human approval remains the rule for consequential external actions.</h2>',
            $harness,
        );
        $this->assertStringContainsString('<strong>Defense in depth, not hostile containment</strong>', $harness);
        $this->assertStringContainsString('These controls are bypassable.', $harness);
        $this->assertStringContainsString('workflow guardrails for cooperative agent hosts', $harness);
        $this->assertStringContainsString('unrestricted host access', $harness);
        $this->assertStringContainsString('cannot prove that every possible execution path will comply', $harness);
        $this->assertStringNotContainsString(
            '<h2>Automation stops at consequential external actions.</h2>',
            $harness,
        );
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

    public function test_public_pages_do_not_use_em_dashes(): void
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

    public function test_llms_txt_uses_the_proposed_markdown_structure(): void
    {
        $llms = file_get_contents(base_path('site/llms.txt'));

        $this->assertIsString($llms);
        $this->assertMatchesRegularExpression('/\A# ArtifactFlow\n\n> .+\n/', $llms);
        $this->assertStringContainsString('## Product', $llms);
        $this->assertStringContainsString(
            '- [Home](https://artifactflow.app/):',
            $llms,
        );
        $this->assertStringContainsString('## Repository documentation', $llms);
        $this->assertStringContainsString(
            '- [Threat model](https://github.com/Gadsotek/artifactflow/blob/main/THREAT-MODEL.md):',
            $llms,
        );
        $this->assertDoesNotMatchRegularExpression('/^- [^[]+:\s+https:\/\//m', $llms);
    }

    public function test_public_copy_frames_artifactflow_as_a_versioned_artifact_vault(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));
        $readme = file_get_contents(base_path('README.md'));
        $agents = file_get_contents(base_path('AGENTS.md'));
        $llms = file_get_contents(base_path('site/llms.txt'));

        $this->assertIsString($homepage);
        $this->assertIsString($readme);
        $this->assertIsString($agents);
        $this->assertIsString($llms);
        $this->assertStringContainsString(
            '<h1>Keep the artifact. Keep the source. Keep every version.</h1>',
            $homepage,
        );
        $this->assertStringContainsString('The missing artifact layer between AI chat and production.', $homepage);
        $this->assertStringContainsString('Preserves the output, not the conversation.', $homepage);
        $this->assertStringContainsString('A self-hosted, versioned artifact vault', $homepage);
        $this->assertStringContainsString('href="/engineering-harness/"', $homepage);
        $this->assertStringNotContainsString('artifactflow.untrusted_data', $homepage);
        $this->assertStringNotContainsString('PostgreSQL with verified TLS', $homepage);
        $this->assertStringContainsString('versioned artifact vault', $readme);
        $this->assertStringContainsString('versioned artifact vault', $agents);
        $this->assertStringContainsString('versioned artifact vault', $llms);
        $this->assertStringNotContainsString('internal knowledge base', $readme);
        $this->assertStringNotContainsString('Confluence on steroids', $agents);
    }

    public function test_homepage_conversion_elements_preserve_the_locked_positioning(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));

        $this->assertIsString($homepage);
        $this->assertStringContainsString(
            '<a class="button" href="/self-hosting/#local">Evaluate locally →</a>',
            $homepage,
        );
        $this->assertStringContainsString('<p class="kicker">The gap after generation</p>', $homepage);
        $this->assertStringNotContainsString('<p class="kicker">A category of its own</p>', $homepage);

        $workflow = strpos($homepage, 'id="workflow"');
        $agents = strpos($homepage, 'id="agents"');
        $gallery = strpos($homepage, 'id="gallery"');
        $safety = strpos($homepage, 'id="safety"');

        $this->assertIsInt($workflow);
        $this->assertIsInt($agents);
        $this->assertIsInt($gallery);
        $this->assertIsInt($safety);
        $this->assertTrue($workflow < $agents);
        $this->assertTrue($agents < $gallery);
        $this->assertTrue($gallery < $safety);
        $this->assertStringNotContainsString('id="capabilities"', $homepage);
        $this->assertStringNotContainsString('Inside the current alpha', $homepage);
        $this->assertStringNotContainsString('The vault around the artifact.', $homepage);
        $this->assertStringNotContainsString('class="feature-grid"', $homepage);
    }

    public function test_homepage_hero_names_isolated_runnable_html_as_the_first_use_case(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));

        $this->assertIsString($homepage);
        $this->assertStringContainsString(
            'run untrusted single-file HTML on a separate origin',
            $homepage,
        );
        $this->assertStringContainsString('<p class="hero-wedge">', $homepage);
        $this->assertStringContainsString('<strong>Built first for executable HTML</strong>', $homepage);
        $this->assertStringContainsString(
            'run their untrusted code on a separate origin away from the app that holds your login.',
            $homepage,
        );
        $this->assertStringContainsString(
            '<div class="signal"><strong>Runnable HTML</strong><span>Separate origin, no app cookies</span></div>',
            $homepage,
        );
        $this->assertStringNotContainsString('safely run untrusted HTML', $homepage);
    }

    public function test_homepage_compresses_audience_terminology_proof_and_evaluation_copy(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));
        $mcpPage = file_get_contents(base_path('site/mcp/index.html'));

        $this->assertIsString($homepage);
        $this->assertIsString($mcpPage);
        $this->assertStringContainsString(
            'Built for technical teams that regularly create small internal tools, operational documents, diagrams, and prototypes with AI.',
            $homepage,
        );
        $this->assertStringContainsString('<p class="artifact-terms">', $homepage);
        $this->assertStringContainsString(
            '<strong>Terminology:</strong> An artifact is the managed record. Each version retains its authoritative source or original; previews and runtimes are derived ways to use it.',
            $homepage,
        );
        $this->assertStringNotContainsString(
            'future binary documents retain private originals and bounded derivatives',
            $homepage,
        );
        $this->assertStringContainsString(
            'Keep their authoritative source, find them later, preview them, run generated HTML away from the authenticated app, and preserve every revision.',
            $homepage,
        );
        $this->assertStringContainsString('<h2>One vault for people and agents.</h2>', $homepage);
        $this->assertStringContainsString('against an isolated test database', $mcpPage);
        $this->assertStringContainsString('<p class="kicker">Understand the boundaries</p>', $homepage);
        $this->assertStringContainsString(
            '<h2 id="details-heading">Follow each concern to its source.</h2>',
            $homepage,
        );
        $this->assertStringNotContainsString(
            'The workflow is simple. Its boundaries are explicit.',
            $homepage,
        );
        $this->assertStringNotContainsString('<p class="kicker">The explanations</p>', $homepage);
        $this->assertStringNotContainsString('The homepage stays focused on the artifact lifecycle.', $homepage);
        $this->assertStringNotContainsString('Select any image to inspect it full size.', $homepage);
        $this->assertSame(4, substr_count($homepage, '<span class="preview-hint">View full size</span>'));
        $this->assertStringContainsString(
            'Executable artifacts are currently limited to single-file HTML. Network access is intentionally constrained by the runtime security model.',
            $homepage,
        );
        $this->assertStringNotContainsString(
            'Every write keeps the normal version and audit path.',
            $homepage,
        );
        $this->assertStringContainsString('<h2>Run ArtifactFlow locally.</h2>', $homepage);
        $this->assertStringContainsString(
            'Evaluate the artifact workflow and security boundaries against your own use case.',
            $homepage,
        );
        $this->assertStringContainsString(
            '<a class="button button-primary" href="/self-hosting/#local">Run locally →</a>',
            $homepage,
        );
        $this->assertStringContainsString(
            '<a class="button button-light" href="https://github.com/Gadsotek/artifactflow">Inspect the source on GitHub ↗</a>',
            $homepage,
        );
        $this->assertStringNotContainsString('Why not GitHub', $homepage);
        $this->assertStringNotContainsString('Why not Notion', $homepage);
    }

    public function test_mcp_proof_is_static_by_default_and_animates_only_on_request(): void
    {
        $homepage = file_get_contents(base_path('site/index.html'));
        $mcpPage = file_get_contents(base_path('site/mcp/index.html'));
        $css = file_get_contents(base_path('site/assets/site.css'));
        $javascript = file_get_contents(base_path('site/assets/site.js'));

        $this->assertIsString($homepage);
        $this->assertIsString($mcpPage);
        $this->assertIsString($css);
        $this->assertIsString($javascript);
        $this->assertStringContainsString(
            'data-mcp-animation data-mcp-layout="compact"',
            $homepage,
        );
        $this->assertSame(3, substr_count($homepage, 'class="mcp-compact-step"'));
        $this->assertStringContainsString('data-mcp-step="search"', $homepage);
        $this->assertStringContainsString('data-mcp-step="read"', $homepage);
        $this->assertStringContainsString('data-mcp-step="update"', $homepage);
        $this->assertStringContainsString('page_uid: 01ky7atfyh…0hef0f', $homepage);
        $this->assertStringContainsString('base_version_uid: 01KY7ATFYM…53H9NN', $homepage);
        $this->assertStringNotContainsString('page_uid: 01ky7atf…', $homepage);
        $this->assertStringNotContainsString('base_version_uid: 01KY7ATF…', $homepage);
        $this->assertStringContainsString('data-mcp-play>▶ Replay</button>', $homepage);
        $this->assertStringContainsString(
            'Captured from real MCP calls against an isolated test database. Identifiers are shortened for display; the full session is on the MCP page.',
            $homepage,
        );
        $this->assertStringContainsString('href="/mcp/#session">See the full MCP session →</a>', $homepage);
        $this->assertStringNotContainsString('Show more', $homepage);

        $this->assertStringContainsString('<section id="session">', $mcpPage);
        $this->assertStringContainsString('data-mcp-animation data-mcp-layout="session"', $mcpPage);
        $this->assertSame(4, substr_count($mcpPage, 'class="mcp-session-step"'));
        $this->assertStringContainsString('data-mcp-step="conflict"', $mcpPage);
        $this->assertStringContainsString('"type": "conflict"', $mcpPage);
        $this->assertStringContainsString('"retryable": true', $mcpPage);
        $this->assertStringContainsString('/assets/site.js?v=20260723-1', $mcpPage);

        $this->assertStringContainsString('@keyframes mcp-compact-cycle', $css);
        $this->assertStringContainsString('@keyframes mcp-session-cycle', $css);
        $this->assertStringContainsString('.mcp-animation.is-playing', $css);
        $this->assertStringContainsString(
            "@media (prefers-reduced-motion: reduce) {\n  html",
            $css,
        );
        $this->assertStringContainsString(
            ".mcp-animation.is-playing [data-mcp-step] {\n    animation: none;",
            $css,
        );
        $this->assertStringContainsString('[data-mcp-play]', $javascript);
        $this->assertStringContainsString("animation.classList.add('is-playing');", $javascript);
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)')", $javascript);
    }

    public function test_repository_descriptions_share_the_artifact_vault_category(): void
    {
        $expectedCopy = [
            'CONTRIBUTING.md' => 'versioned artifact vault',
            'SECURITY.md' => 'versioned artifact vault',
            'docs/ARCHITECTURE.md' => 'versioned artifact vault',
            'docs/architecture/README.md' => 'versioned artifact vault',
            'site/README.md' => 'versioned artifact vault',
            'package.json' => 'Versioned artifact vault',
            'composer.json' => 'Versioned artifact vault',
        ];

        foreach ($expectedCopy as $relativePath => $positioning) {
            $copy = file_get_contents(base_path($relativePath));

            $this->assertIsString($copy);
            $this->assertStringContainsString($positioning, $copy, sprintf('%s must share the product category.', $relativePath));
        }
    }

    public function test_roadmap_prioritizes_pdf_and_word_document_artifacts(): void
    {
        $roadmap = file_get_contents(base_path('ROADMAP.md'));
        $roadmapPage = file_get_contents(base_path('site/roadmap/index.html'));

        $this->assertIsString($roadmap);
        $this->assertIsString($roadmapPage);
        $this->assertStringContainsString('## Focus: searchable PDF artifacts', $roadmap);
        $this->assertStringContainsString('## Focus: searchable Word document artifacts', $roadmap);
        $this->assertStringContainsString('Searchable PDF artifacts', $roadmapPage);
        $this->assertStringContainsString('Searchable Word documents', $roadmapPage);
        $this->assertStringContainsString('DOCX', $roadmapPage);
        $this->assertStringContainsString(
            '[GitHub issue #32](https://github.com/Gadsotek/artifactflow/issues/32)',
            $roadmap,
        );
        $this->assertStringContainsString(
            '[GitHub issue #33](https://github.com/Gadsotek/artifactflow/issues/33)',
            $roadmap,
        );
        $this->assertStringContainsString(
            'href="https://github.com/Gadsotek/artifactflow/issues/32">Track PDF issue #32 ↗</a>',
            $roadmapPage,
        );
        $this->assertStringContainsString(
            'href="https://github.com/Gadsotek/artifactflow/issues/33">Track Word issue #33 ↗</a>',
            $roadmapPage,
        );
    }

    public function test_artifact_workflow_separates_current_invariants_guidance_and_roadmap_direction(): void
    {
        $workflowDocumentation = file_get_contents(base_path('docs/ARTIFACT-LIFECYCLE.md'));
        $workflowPage = file_get_contents(base_path('site/workflow/index.html'));
        $homepage = file_get_contents(base_path('site/index.html'));
        $readme = file_get_contents(base_path('README.md'));
        $llms = file_get_contents(base_path('site/llms.txt'));

        $this->assertIsString($workflowDocumentation);
        $this->assertIsString($workflowPage);
        $this->assertIsString($homepage);
        $this->assertIsString($readme);
        $this->assertIsString($llms);

        foreach ([$workflowDocumentation, $workflowPage] as $workflow) {
            $this->assertStringContainsString('Current invariant', $workflow);
            $this->assertStringContainsString('Product guidance', $workflow);
            $this->assertStringContainsString('Roadmap direction', $workflow);
            $this->assertStringContainsString('stable artifact UID', $workflow);
            $this->assertStringContainsString('Guidance, not an enforced invariant', $workflow);
            $this->assertStringContainsString('Draft is a lifecycle status, not mutable content', $workflow);
            $this->assertStringContainsString('creates no version', $workflow);
            $this->assertStringContainsString('metadata revision', $workflow);
            $this->assertStringContainsString('not a content-version snapshot', $workflow);
            $this->assertStringContainsString('PDF and DOCX support is roadmap work, not current behavior', $workflow);
            $this->assertStringContainsString('Per-version catalog metadata is not promised', $workflow);
            $this->assertStringContainsString('optional generator source', $workflow);
        }

        $this->assertStringContainsString('href="/workflow/"', $homepage);
        $this->assertStringContainsString(
            '- [Artifact workflow](docs/ARTIFACT-LIFECYCLE.md):',
            $readme,
        );
        $this->assertStringContainsString(
            '- [Artifact workflow](https://artifactflow.app/workflow/):',
            $llms,
        );
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
