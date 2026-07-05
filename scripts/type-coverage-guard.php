<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/type-coverage-guard.php <report.json> <minimum>\n");
    exit(2);
}

$reportPath = $argv[1];
$minimum = filter_var($argv[2], FILTER_VALIDATE_FLOAT);

if ($minimum === false) {
    fwrite(STDERR, "Type coverage minimum must be a number.\n");
    exit(2);
}

$contents = @file_get_contents($reportPath);
if ($contents === false) {
    fwrite(STDERR, sprintf("Type coverage report was not found at %s.\n", $reportPath));
    exit(2);
}

try {
    $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, sprintf("Type coverage report is invalid JSON: %s\n", $exception->getMessage()));
    exit(2);
}

if (! is_array($report) || ! array_key_exists('total', $report)) {
    fwrite(STDERR, "Type coverage report is missing the total field.\n");
    exit(2);
}

$total = filter_var($report['total'], FILTER_VALIDATE_FLOAT);
if ($total === false) {
    fwrite(STDERR, "Type coverage report total must be numeric.\n");
    exit(2);
}

if ($total < $minimum) {
    fwrite(
        STDERR,
        sprintf("Type coverage %.1f%% is below required %.1f%%.\n", $total, $minimum),
    );
    exit(1);
}

printf("Type coverage %.1f%% meets required %.1f%%.\n", $total, $minimum);
