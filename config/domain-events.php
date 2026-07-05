<?php

declare(strict_types=1);

return [
    'dispatch_batch_size' => 100,
    'retention_days' => (int) env('DOMAIN_EVENT_RETENTION_DAYS', 90),
];
