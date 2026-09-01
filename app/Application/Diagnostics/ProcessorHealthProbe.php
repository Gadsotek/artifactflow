<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

interface ProcessorHealthProbe
{
    public function xlsx(ProcessorHealthTarget $target): ProcessorHealthProbeResult;

    public function docx(ProcessorHealthTarget $target): ProcessorHealthProbeResult;

    public function pdf(ProcessorHealthTarget $target): ProcessorHealthProbeResult;
}
