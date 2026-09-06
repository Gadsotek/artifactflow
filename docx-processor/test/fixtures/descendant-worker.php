<?php

declare(strict_types=1);

// Fault injection: the converter leader exits while a same-group child remains.
$marker = $argv[1];
$exitCode = (int) $argv[2];
$pid = pcntl_fork();
if ($pid === -1) {
    exit(70);
}
if ($pid === 0) {
    usleep(900_000);
    file_put_contents($marker, 'survived');
    exit(0);
}
exit($exitCode);
