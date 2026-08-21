<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\PageContentPreparer;
use App\Application\PageCatalog\PageContentStrategyRegistry;
use App\Application\PageCatalog\PageVersionAppender;
use App\Application\PageCatalog\PageVersionWriter;
use App\Application\PageCatalog\ReindexSearchText;
use App\Domain\PageCatalog\PageType;
use ReflectionClass;
use Tests\TestCase;

final class PageContentPreparationArchitectureTest extends TestCase
{
    public function test_version_appender_exposes_no_prepared_content_bypass(): void
    {
        $appender = new ReflectionClass(PageVersionAppender::class);

        $this->assertFalse(
            $appender->hasMethod('appendPrepared')
            && $appender->getMethod('appendPrepared')->isPublic(),
        );
    }

    public function test_version_writer_does_not_reinspect_prepared_image_content_for_its_extension(): void
    {
        $reflection = new ReflectionClass(PageVersionWriter::class);
        $path = $reflection->getFileName();
        $this->assertIsString($path);
        $source = file_get_contents($path);
        $this->assertIsString($source);

        $this->assertStringNotContainsString('inspectStored', $source);
    }

    public function test_every_page_type_has_a_registered_content_strategy(): void
    {
        $strategies = app(PageContentStrategyRegistry::class);

        foreach (PageType::cases() as $type) {
            $this->assertContains($type, $strategies->for($type)->supportedTypes());
        }
    }

    public function test_content_orchestration_does_not_special_case_raster_images(): void
    {
        foreach ([PageContentPreparer::class, PageVersionWriter::class, ReindexSearchText::class] as $class) {
            $reflection = new ReflectionClass($class);
            $path = $reflection->getFileName();
            $this->assertIsString($path);
            $source = file_get_contents($path);
            $this->assertIsString($source);

            $this->assertStringNotContainsString('PageType::Image', $source, $class);
        }
    }

    public function test_reindex_uses_the_content_preparer_as_its_strategy_facade(): void
    {
        $reflection = new ReflectionClass(ReindexSearchText::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $types = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $constructor->getParameters(),
        );

        $this->assertContains(PageContentPreparer::class, $types);
        $this->assertNotContains(PageContentStrategyRegistry::class, $types);
        $this->assertTrue((new ReflectionClass(PageContentPreparer::class))->hasMethod('textProjection'));
    }

    public function test_external_pdf_processing_is_not_admitted_inside_generic_search_reindex_transactions(): void
    {
        $preparer = app(PageContentPreparer::class);

        $this->assertTrue($preparer->supportsSearchTextReindex(PageType::Markdown));
        $this->assertTrue($preparer->supportsSearchTextReindex(PageType::HtmlArtifact));
        $this->assertTrue($preparer->supportsSearchTextReindex(PageType::Image));
        $this->assertFalse($preparer->supportsSearchTextReindex(PageType::Pdf));
    }
}
