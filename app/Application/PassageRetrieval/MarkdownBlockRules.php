<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ConfigurableExtensionInterface;
use League\Config\ConfigurationBuilderInterface;

/** Use the application's CommonMark block grammar without building an inline delimiter or link tree. */
final class MarkdownBlockRules implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        (new CommonMarkCoreExtension())->configureSchema($builder);
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $core = new Environment();
        $core->addExtension(new CommonMarkCoreExtension());
        // getBlockStartParsers() yields the core rules in parsing priority order.
        foreach ($core->getBlockStartParsers() as $parser) {
            $environment->addBlockStartParser($parser);
        }
    }
}
