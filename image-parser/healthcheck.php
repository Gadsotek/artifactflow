<?php

declare(strict_types=1);

use ArtifactFlow\ImageParser\ParserConfiguration;
use ArtifactFlow\ImageParser\RasterNormalizer;

require __DIR__ . '/src/ImageParser.php';

try {
    $configuration = ParserConfiguration::fromEnvironment();
    (new RasterNormalizer($configuration))->verifyHealth();
} catch (Throwable) {
    exit(1);
}

exit(0);
