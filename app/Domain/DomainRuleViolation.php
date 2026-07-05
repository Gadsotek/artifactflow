<?php

declare(strict_types=1);

namespace App\Domain;

use DomainException;

/**
 * Base exception for user-facing business-rule violations raised by application
 * handlers. Boundary catch sites (controllers, console commands) may surface its
 * message to the end user. It deliberately does not extend SPL
 * InvalidArgumentException so vendor and framework InvalidArgumentException
 * instances thrown inside handlers stay internal server errors instead of
 * leaking their messages to users.
 */
class DomainRuleViolation extends DomainException
{
}
