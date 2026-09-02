<?php

declare(strict_types=1);

use ArtifactFlow\DocxProcessor\DocxPackageInspector;
use ArtifactFlow\DocxProcessor\LibreOfficeConverter;
use ArtifactFlow\DocxProcessor\ProcessorConfiguration;

require dirname(__DIR__) . '/src/DocxProcessor.php';

/** @param non-empty-list<string> $command */
function runCommand(array $command): void
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [
            'HOME' => '/tmp',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'PATH' => '/usr/bin:/bin',
            'SAL_DISABLE_OPENCL' => '1',
            'SAL_USE_VCLPLUGIN' => 'svp',
        ],
        ['bypass_shell' => true, 'suppress_errors' => true],
    );

    if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
        throw new RuntimeException('Could not start the LibreOffice DOCX export proof.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (
        $exitCode !== 0
        || !is_string($stdout)
        || !is_string($stderr)
        || strlen($stdout) > 16_384
        || strlen($stderr) > 16_384
    ) {
        throw new RuntimeException(sprintf(
            'LibreOffice could not produce the DOCX export proof (exit %d, stdout %s, stderr %s).',
            $exitCode,
            json_encode($stdout, JSON_THROW_ON_ERROR),
            json_encode($stderr, JSON_THROW_ON_ERROR),
        ));
    }
}

function discardTree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $entries = scandir($root);
    if (!is_array($entries)) {
        throw new RuntimeException('Could not inspect the LibreOffice DOCX export proof directory.');
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $root . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            discardTree($path);
        } elseif (!unlink($path)) {
            throw new RuntimeException('Could not remove a LibreOffice DOCX export proof file.');
        }
    }

    if (!rmdir($root)) {
        throw new RuntimeException('Could not remove the LibreOffice DOCX export proof directory.');
    }
}

$root = '/tmp/artifactflow-libreoffice-docx-export-' . bin2hex(random_bytes(12));
$inputDirectory = $root . '/input';
$outputDirectory = $root . '/output';
$profileDirectory = $root . '/profile';

try {
    foreach ([$root, $inputDirectory, $outputDirectory, $profileDirectory] as $directory) {
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create the LibreOffice DOCX export proof workspace.');
        }
    }

    $sourcePath = $inputDirectory . '/source.html';
    $source = '<!doctype html><html><body><h1>LibreOffice DOCX export</h1><p>Searchable interoperability proof.</p></body></html>';
    if (file_put_contents($sourcePath, $source, LOCK_EX) !== strlen($source) || !chmod($sourcePath, 0600)) {
        throw new RuntimeException('Could not stage the LibreOffice DOCX export proof source.');
    }

    runCommand([
        '/usr/bin/setsid',
        '/usr/bin/timeout',
        '-s',
        'KILL',
        '30',
        '/usr/bin/soffice',
        '--headless',
        '--nologo',
        '--nodefault',
        '--nolockcheck',
        '--norestore',
        '--invisible',
        '-env:UserInstallation=file://' . $profileDirectory,
        '--convert-to',
        'docx:Office Open XML Text',
        '--outdir',
        $outputDirectory,
        $sourcePath,
    ]);

    $docxPath = $outputDirectory . '/source.docx';
    $docx = is_file($docxPath)
        ? file_get_contents($docxPath, false, null, 0, ProcessorConfiguration::MAX_INPUT_BYTES + 1)
        : false;
    if (!is_string($docx) || $docx === '' || strlen($docx) > ProcessorConfiguration::MAX_INPUT_BYTES) {
        throw new RuntimeException('LibreOffice did not produce a bounded DOCX export proof.');
    }

    $facts = (new DocxPackageInspector())->inspect($docx);
    $pdf = (new LibreOfficeConverter())->convert($docx)->pdfBytes;
    if (!str_starts_with($pdf, '%PDF-') || !str_contains($pdf, '%%EOF')) {
        throw new RuntimeException('The LibreOffice DOCX export did not produce a complete PDF preview.');
    }

    echo sprintf(
        "LibreOffice DOCX export compatibility passed (%d parts, %d relationships).\n",
        $facts->entryCount,
        $facts->relationshipCount,
    );
} finally {
    discardTree($root);
}
