<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

final class MimeParameterSecurityTest extends TestCase
{
    #[DataProvider('invalidParameterNames')]
    public function test_constructor_rejects_non_token_parameter_names(string $name): void
    {
        $this->expectException(RfcComplianceException::class);

        new ParameterizedHeader('Content-Disposition', 'attachment', [$name => 'document.pdf']);
    }

    #[DataProvider('invalidParameterNames')]
    public function test_single_parameter_mutation_rejects_non_token_names(string $name): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment');

        $this->expectException(RfcComplianceException::class);

        $header->setParameter($name, 'document.pdf');
    }

    #[DataProvider('invalidParameterNames')]
    public function test_bulk_parameter_mutation_rejects_non_token_names(string $name): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment');

        $this->expectException(RfcComplianceException::class);

        $header->setParameters([$name => 'document.pdf']);
    }

    public function test_valid_parameters_preserve_encoded_filenames(): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'report.pdf']);
        $this->assertSame('attachment; filename=report.pdf', $header->getBodyAsString());

        $header->setParameter('filename', 'report 2026.pdf');
        $this->assertSame('attachment; filename="report 2026.pdf"', $header->getBodyAsString());

        $header->setParameters(['filename' => 'Příloha.pdf']);
        $this->assertSame('Příloha.pdf', $header->getParameter('filename'));
        $this->assertStringContainsString('filename*=', $header->getBodyAsString());
        $this->assertStringContainsString('P%C5%99%C3%ADloha.pdf', $header->getBodyAsString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParameterNames(): iterable
    {
        yield 'injected header' => ["filename\r\nX-Injected: yes"];
        yield 'parameter separator' => ['file;name'];
        yield 'whitespace' => ["file\tname"];
        yield 'empty name' => [''];
        yield 'non ASCII' => ['příloha'];
    }
}
