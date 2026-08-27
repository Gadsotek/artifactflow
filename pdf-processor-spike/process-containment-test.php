<?php

declare(strict_types=1);

use ArtifactFlow\PdfProcessor\EngineUnavailable;
use ArtifactFlow\PdfProcessor\PdfBoxEngine;

require '/srv/pdf-processor-spike/src/PdfProcessor.php';

$suffix = bin2hex(random_bytes(8));
$pidPath = '/tmp/artifactflow-descendant-' . $suffix . '.pid';
$observedPath = '/tmp/artifactflow-observed-' . $suffix;
$laterNeedle = 'later tenant private PDF bytes ' . $suffix;
$descendantPid = null;

$watcher = <<<'PHP'
$ignoredPath = $argv[1];
$observedPath = $argv[2];
$needle = $argv[3];
$deadline = microtime(true) + 5;

while (microtime(true) < $deadline) {
    foreach (glob('/tmp/artifactflow-pdf-*') ?: [] as $path) {
        if ($path === $ignoredPath || !is_file($path)) {
            continue;
        }

        $bytes = @file_get_contents($path);

        if (is_string($bytes) && str_contains($bytes, $needle)) {
            file_put_contents($observedPath, 'observed');
            exit(0);
        }
    }

    usleep(10_000);
}
PHP;

$maliciousEngine = sprintf(
    <<<'PHP'
$inputPath = $argv[count($argv) - 1];
$pipes = [];
$process = @proc_open(
    [PHP_BINARY, '-r', %s, $inputPath, %s, %s],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes,
);

if (is_resource($process)) {
    $status = proc_get_status($process);

    if (is_int($status['pid'] ?? null)) {
        file_put_contents(%s, (string) $status['pid']);
    }
}

sleep(10);
PHP,
    var_export($watcher, true),
    var_export($observedPath, true),
    var_export($laterNeedle, true),
    var_export($pidPath, true),
);

$launcher = '/usr/local/bin/artifactflow-process-deny';
$timedOut = false;

try {
    $engine = new PdfBoxEngine(
        command: [$launcher, PHP_BINARY, '-r', $maliciousEngine],
        timeoutSeconds: 1,
    );
    $engine->inspect('first request bytes');
} catch (EngineUnavailable) {
    $timedOut = true;
}

if (is_file($pidPath)) {
    $pidText = file_get_contents($pidPath);

    if (is_string($pidText) && ctype_digit($pidText)) {
        $descendantPid = (int) $pidText;
    }
}

$descendantAliveAfterTimeout = is_int($descendantPid)
    && $descendantPid > 1
    && posix_kill($descendantPid, 0);

$normalEngine = new PdfBoxEngine(
    command: [
        $launcher,
        PHP_BINARY,
        '-r',
        'usleep(750000); echo \'{"pages":1,"pdf_version":"1.7","truncated":false,"text":"safe"}\';',
    ],
    timeoutSeconds: 2,
);
$normalEngine->inspect($laterNeedle);
usleep(100_000);

$laterInputObserved = is_file($observedPath);

if ($descendantAliveAfterTimeout && is_int($descendantPid)) {
    posix_kill($descendantPid, SIGKILL);
}

foreach ([$pidPath, $observedPath] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

$result = [
    'descendant_alive_after_timeout' => $descendantAliveAfterTimeout,
    'later_input_observed' => $laterInputObserved,
];

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($timedOut && !$descendantAliveAfterTimeout && !$laterInputObserved ? 0 : 1);
