<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

final class PublicSitePositioningTest extends TestCase
{
    public function test_public_positioning_leads_with_a_shared_model_agnostic_workspace(): void
    {
        foreach (['README.md', 'site/index.html', 'site/llms.txt', 'site/README.md', 'package.json', 'composer.json'] as $path) {
            $copy = file_get_contents(base_path($path));
            $this->assertIsString($copy);
            $this->assertStringContainsString('self-hosted', $copy, $path);
            $this->assertStringContainsString('workspace', $copy, $path);
            $this->assertStringContainsString('AI-generated', $copy, $path);
        }

        $xpath = $this->page('site/index.html');
        $this->assertSame('Your AI work, in one place.', $xpath->evaluate('normalize-space(//h1)'));
        $hero = $xpath->evaluate('string(//section[@id="top"])');
        $this->assertIsString($hero);
        foreach (['model-agnostic', 'chats, files, and people', 'Self-host ArtifactFlow', 'View on GitHub'] as $required) {
            $this->assertStringContainsString($required, $hero);
        }
        $this->assertStringNotContainsString('Built first for executable HTML', $hero);
    }

    public function test_homepage_keeps_the_product_story_ahead_of_format_and_security_detail(): void
    {
        $xpath = $this->page('site/index.html');
        $main = $xpath->evaluate('string(//main)');
        $this->assertIsString($main);
        $this->assertLessThan(850, str_word_count($main));
        foreach (['Switch models. Keep your work.', 'One workspace for people and AI.', 'MCP-compatible', 'no automatic chat import', 'retention', 'default-off PDF, XLSX, and DOCX', 'not independently audited'] as $required) {
            $this->assertStringContainsString($required, $main);
        }

        $html = file_get_contents(base_path('site/index.html'));
        $this->assertIsString($html);
        $previous = -1;
        foreach (['product', 'agents', 'gallery', 'self-host', 'safety'] as $id) {
            $position = strpos($html, sprintf('id="%s"', $id));
            $this->assertIsInt($position);
            $this->assertGreaterThan($previous, $position);
            $previous = $position;
        }
        $this->assertStringNotContainsString('artifactflow.untrusted_data', $html);
        $this->assertStringNotContainsString('No public sharing,', $html);
    }

    public function test_public_pages_keep_styles_and_executable_scripts_in_external_files(): void
    {
        $pages = glob(base_path('site/{,*/,guides/*/}index.html'), GLOB_BRACE);
        $this->assertIsArray($pages);
        $this->assertCount(10, $pages);
        foreach ($pages as $path) {
            $xpath = $this->page($path, false);
            $inline = $xpath->query('//style | //*[@style] | //script[not(@src) and not(@type="application/ld+json")]');
            $this->assertNotFalse($inline);
            $this->assertSame(0, $inline->length, $path);
            $this->assertSame('#main', $xpath->evaluate('string(//a[@class="skip-link"]/@href)'), $path);
        }
    }

    public function test_readme_is_a_concise_entry_point_with_operational_details_linked(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $this->assertIsString($readme);
        $this->assertLessThan(1100, str_word_count($readme));
        $this->assertStringNotContainsString('—', $readme);
        foreach (['make up', 'make shell', 'artifactflow:install', 'make doctor', 'two separate HTTPS origins', 'default-off', 'AGPL', 'docs/OPERATIONS.md', 'CONTRIBUTING.md'] as $required) {
            $this->assertStringContainsString($required, $readme);
        }
    }

    public function test_mcp_examples_keep_current_write_and_provenance_requirements(): void
    {
        $xpath = $this->page('site/mcp/index.html');
        $updates = $xpath->query('//pre[@data-mcp-step="update" or @data-mcp-step="conflict"]');
        $this->assertNotFalse($updates);
        $requests = 0;
        foreach ($updates as $block) {
            $this->assertInstanceOf(DOMElement::class, $block);
            if (str_contains($block->textContent, '"base_version_uid"')) {
                $this->assertStringContainsString('"change_summary"', $block->textContent);
                ++$requests;
            }
        }
        $this->assertSame(2, $requests);
        $copy = $xpath->evaluate('string(//main)');
        $this->assertIsString($copy);
        $this->assertStringContainsString('partial', $copy);
        $this->assertStringNotContainsString('An AI claim requires a provider and exact provider-defined model ID.', $copy);
    }

    public function test_workflow_has_a_readable_visual_journey_without_requiring_javascript(): void
    {
        $xpath = $this->page('site/workflow/index.html');
        $steps = $xpath->query('//ol[@class="journey-steps"]/li');
        $this->assertNotFalse($steps);
        $this->assertSame(3, $steps->length);
        foreach (['create', 'identity', 'find', 'reuse', 'versions', 'metadata', 'provenance'] as $id) {
            $targets = $xpath->query(sprintf('//*[@id="%s"]', $id));
            $this->assertNotFalse($targets);
            $this->assertSame(1, $targets->length);
        }
        $copy = $xpath->evaluate('string(//main)');
        $this->assertIsString($copy);
        $this->assertLessThan(450, str_word_count($copy));
        $this->assertStringContainsString('Illustrative workflow', $copy);
        $this->assertStringContainsString('no automatic chat import', $copy);
    }

    private function page(string $path, bool $relative = true): DOMXPath
    {
        $html = file_get_contents($relative ? base_path($path) : $path);
        $this->assertIsString($html);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded);

        return new DOMXPath($document);
    }
}
