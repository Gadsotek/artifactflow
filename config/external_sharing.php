<?php

declare(strict_types=1);

return [
    'max_active_per_page' => (int) env('EXTERNAL_SHARE_MAX_ACTIVE_PER_PAGE', 20),
    'max_active_per_installation' => (int) env('EXTERNAL_SHARE_MAX_ACTIVE_PER_INSTALLATION', 10000),
    'max_view_sessions_per_share' => (int) env('EXTERNAL_SHARE_MAX_VIEW_SESSIONS_PER_SHARE', 100),
    'session_retention_hours' => 24,
    'terminal_share_retention_days' => 90,
];
