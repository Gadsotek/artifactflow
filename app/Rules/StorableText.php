<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\PageCatalog\PageContentEncoding;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects free-text input that a PostgreSQL text column cannot store -- malformed
 * UTF-8 or a NUL/other C0 control byte. Without this the value passes the string and
 * length rules (mb_strlen counts a lone 0xFF as one character) and only fails when
 * bound to a query, surfacing as a 500 (SQLSTATE 22021) instead of a clean 422. Tab,
 * line feed, and carriage return stay allowed, matching the page-content guard so the
 * two boundaries agree on what "storable text" means.
 */
final class StorableText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The accompanying 'string' rule reports a non-string; only screen bytes here.
        if (is_string($value) && !PageContentEncoding::isStorable($value)) {
            $fail('The :attribute contains characters that are not allowed.');
        }
    }
}
