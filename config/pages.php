<?php

declare(strict_types=1);

return [
    'max_markdown_bytes' => (int) env('PAGE_MARKDOWN_MAX_BYTES', 5 * 1024 * 1024),
    'max_html_bytes' => (int) env('PAGE_HTML_MAX_BYTES', 5 * 1024 * 1024),
    'artifact_max_bytes' => (int) env('ARTIFACT_MAX_BYTES', 10 * 1024 * 1024),
    'max_workspace_storage_bytes' => (int) env('PAGE_WORKSPACE_MAX_STORAGE_BYTES', 1024 * 1024 * 1024),
    'max_page_storage_bytes' => (int) env('PAGE_MAX_PAGE_STORAGE_BYTES', 100 * 1024 * 1024),
    'max_page_versions' => (int) env('PAGE_MAX_PAGE_VERSIONS', 200),
    'max_tags_per_page' => (int) env('PAGE_MAX_TAGS_PER_PAGE', 25),
    'workspace_invitation_ttl_days' => (int) env('WORKSPACE_INVITATION_TTL_DAYS', 7),
    'workspace_rename_cooldown_seconds' => (int) env('WORKSPACE_RENAME_COOLDOWN_SECONDS', 60),
];
