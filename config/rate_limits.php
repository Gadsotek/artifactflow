<?php

declare(strict_types=1);

return [
    'authenticated_per_minute' => (int) env('AUTHENTICATED_RATE_LIMIT_PER_MINUTE', 120),
    'page_writes_per_minute' => (int) env('PAGE_WRITE_RATE_LIMIT_PER_MINUTE', 30),
    'page_presence_per_minute' => (int) env('PAGE_PRESENCE_RATE_LIMIT_PER_MINUTE', 120),
    'invitations_per_minute' => (int) env('WORKSPACE_INVITATIONS_PER_MINUTE', 10),
    'workspace_creates_per_minute' => (int) env('WORKSPACE_CREATES_PER_MINUTE', 10),
    'invitation_accepts_per_minute' => (int) env('WORKSPACE_INVITATION_ACCEPTS_PER_MINUTE', 10),
    'markdown_previews_per_minute' => (int) env('MARKDOWN_PREVIEW_RATE_LIMIT_PER_MINUTE', 30),
    'artifact_previews_per_minute' => (int) env('ARTIFACT_PREVIEWS_PER_MINUTE', 60),
    'mcp_pre_auth_per_minute' => (int) env('MCP_PRE_AUTH_RATE_LIMIT_PER_MINUTE', 300),
    'mcp_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 60),
    'mcp_writes_per_minute' => (int) env('MCP_WRITE_RATE_LIMIT_PER_MINUTE', 20),
    'admin_step_up_per_minute' => (int) env('ADMIN_STEP_UP_RATE_LIMIT_PER_MINUTE', 5),
    'login_ip_per_minute' => (int) env('LOGIN_IP_RATE_LIMIT_PER_MINUTE', 20),
    'login_account_per_hour' => (int) env('LOGIN_ACCOUNT_RATE_LIMIT_PER_HOUR', 20),
    'password_resets_per_hour' => (int) env('PASSWORD_RESETS_PER_HOUR', 5),
    'two_factor_challenge_per_minute' => (int) env('TWO_FACTOR_CHALLENGE_RATE_LIMIT_PER_MINUTE', 5),
    'two_factor_challenge_account_per_hour' => (int) env('TWO_FACTOR_CHALLENGE_ACCOUNT_RATE_LIMIT_PER_HOUR', 30),
    'two_factor_challenge_ip_per_minute' => (int) env('TWO_FACTOR_CHALLENGE_IP_RATE_LIMIT_PER_MINUTE', 20),
];
