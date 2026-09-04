<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\PageCatalog\DocxProcessingReservation;
use App\Application\PageCatalog\PageVersionStorage;
use App\Application\PageCatalog\XlsxProcessingReservation;
use App\Infrastructure\Security\PreviousApplicationKeyConfiguration;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PageCatalogLifecycleValueTest extends TestCase
{
    public function test_office_processing_reservations_can_retain_only_dispatched_leases(): void
    {
        foreach ([new XlsxProcessingReservation(), new DocxProcessingReservation()] as $reservation) {
            $this->assertTrue($reservation->shouldReleaseLease());

            try {
                $reservation->retainLeaseUntilExpiry();
                $this->fail('An undispatched Office processing lease cannot be retained.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('only after dispatch', $exception->getMessage());
            }

            $reservation->markDispatched();
            $reservation->retainLeaseUntilExpiry();
            $this->assertFalse($reservation->shouldReleaseLease());
        }
    }

    public function test_version_storage_uses_an_eager_loaded_derivative_graph_for_paths_and_bytes(): void
    {
        $version = new PageVersion();
        $version->forceFill([
            'content_storage_path' => 'pages/version/original.xlsx',
            'byte_size' => 11,
        ]);
        $firstDerivative = new PageVersionDerivative();
        $firstDerivative->forceFill([
            'storage_path' => 'pages/version/manifest.json',
            'byte_size' => 7,
        ]);
        $secondDerivative = new PageVersionDerivative();
        $secondDerivative->forceFill([
            'storage_path' => 'pages/version/preview.pdf',
            'byte_size' => 13,
        ]);
        $version->setRelation(
            'derivatives',
            new EloquentCollection([$firstDerivative, $secondDerivative]),
        );

        $storage = new PageVersionStorage();

        $this->assertSame([
            'pages/version/original.xlsx',
            'pages/version/manifest.json',
            'pages/version/preview.pdf',
        ], $storage->paths($version));
        $this->assertSame(31, $storage->bytes($version));
    }

    public function test_previous_application_key_configuration_rejects_malformed_rings_and_ignores_blanks(): void
    {
        $validKey = str_repeat('k', 32);

        $this->assertNull(PreviousApplicationKeyConfiguration::normalizedKeys('not-a-key-ring'));
        $this->assertNull(PreviousApplicationKeyConfiguration::normalizedKeys([$validKey, 123]));
        $this->assertSame(
            [$validKey],
            PreviousApplicationKeyConfiguration::normalizedKeys([' ', $validKey]),
        );
    }
}
