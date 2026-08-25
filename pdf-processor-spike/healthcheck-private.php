<?php

declare(strict_types=1);

use ArtifactFlow\PdfProcessor\ProcessorConfiguration;

require __DIR__ . '/src/PdfProcessor.php';

try {
    ProcessorConfiguration::fromEnvironment();
} catch (Throwable) {
    exit(1);
}

exit(0);
