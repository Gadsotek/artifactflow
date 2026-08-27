<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpReadSection;
use App\Application\Mcp\McpToolArguments;
use App\Domain\DomainRuleViolation;

final readonly class McpReadInput
{
    /**
     * @param list<McpReadSection> $sections
     */
    private function __construct(
        public string $pageUid,
        public array $sections,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        if (!$arguments->has('include')) {
            return new self(
                pageUid: $arguments->requiredString('page_uid'),
                sections: [McpReadSection::Content, McpReadSection::Provenance],
            );
        }

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

        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            sections: $sections,
        );
    }

    public function includes(McpReadSection $section): bool
    {
        return in_array($section, $this->sections, true);
    }
}
