<?php

declare(strict_types=1);

namespace App\Application\Mcp;

enum McpToolErrorType: string
{
    case AuthenticationRequired = 'authentication_required';
    case BlockedContent = 'blocked_content';
    case Conflict = 'conflict';
    case ContentTooLarge = 'content_too_large';
    case ContentUnavailable = 'content_unavailable';
    case InsufficientScope = 'insufficient_scope';
    case InvalidRequest = 'invalid_request';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case UnsupportedContentType = 'unsupported_content_type';
}
