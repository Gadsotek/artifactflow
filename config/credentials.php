<?php

declare(strict_types=1);

return [
    // Expired trusted-device cookies are dead the moment they lapse; 0 prunes them as
    // soon as they expire, a positive value keeps the row a short grace beforehand.
    'trusted_device_retention_days' => (int) env('TRUSTED_DEVICE_RETENTION_DAYS', 0),

    // Revoked or naturally expired MCP tokens stay visible in settings history for this
    // window (measured from the moment they were retired) before the reaper deletes them.
    'mcp_token_retention_days' => (int) env('MCP_TOKEN_RETENTION_DAYS', 30),
];
