<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpReadSection;
use App\Application\Mcp\McpToolArguments;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageContentEncoding;

final readonly class McpReadInput
{
    /**
     * @param list<McpReadSection> $sections
     */
    private function __construct(
        public string $pageUid,
        public array $sections,
        public ?string $xlsxSheet,
        public ?string $xlsxRange,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        $sections = [McpReadSection::Content, McpReadSection::Provenance];

        if ($arguments->has('include')) {
            $sections = [];

            foreach ($arguments->stringList('include') as $value) {
                $section = McpReadSection::tryFrom($value);

                if (!$section instanceof McpReadSection) {
                    throw new DomainRuleViolation('Argument [include] contains an unsupported read section.');
                }

                if (!in_array($section, $sections, true)) {
                    $sections[] = $section;
                }
            }
        }

        $xlsxSheet = $arguments->nullableRawString('xlsx_sheet');
        $xlsxRange = $arguments->nullableString('xlsx_range');

        if (($xlsxSheet === null) !== ($xlsxRange === null)) {
            throw new DomainRuleViolation('Arguments [xlsx_sheet] and [xlsx_range] must be supplied together.');
        }

        if ($xlsxSheet !== null && (strlen($xlsxSheet) > 512 || !PageContentEncoding::isStorable($xlsxSheet))) {
            throw new DomainRuleViolation('Argument [xlsx_sheet] exceeds the supported worksheet-name boundary.');
        }

        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            sections: $sections,
            xlsxSheet: $xlsxSheet,
            xlsxRange: $xlsxRange,
        );
    }

    public function includes(McpReadSection $section): bool
    {
        return in_array($section, $this->sections, true);
    }
}
