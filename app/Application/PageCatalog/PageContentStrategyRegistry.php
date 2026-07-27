<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageType;
use LogicException;

final readonly class PageContentStrategyRegistry
{
    /** @var array<string, PageContentStrategy> */
    private array $strategies;

    /**
     * @param iterable<PageContentStrategy> $strategies
     */
    public function __construct(iterable $strategies)
    {
        $indexed = [];

        foreach ($strategies as $strategy) {
            foreach ($strategy->supportedTypes() as $type) {
                if (isset($indexed[$type->value])) {
                    throw new LogicException(sprintf(
                        'Multiple page content strategies are registered for [%s].',
                        $type->value,
                    ));
                }

                $indexed[$type->value] = $strategy;
            }
        }

        foreach (PageType::cases() as $type) {
            if (!isset($indexed[$type->value])) {
                throw new LogicException(sprintf(
                    'No page content strategy is registered for [%s].',
                    $type->value,
                ));
            }
        }

        $this->strategies = $indexed;
    }

    public function for(PageType $type): PageContentStrategy
    {
        return $this->strategies[$type->value];
    }
}
