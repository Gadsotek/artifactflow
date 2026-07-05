<?php

declare(strict_types=1);

namespace App\Application\Identity;

use RuntimeException;

/**
 * Raised inside the invitation-registration transaction when the invitation is no
 * longer acceptable -- revoked, expired, or already accepted -- as observed under
 * the invitation row lock. It rolls the transaction back so the account created in
 * the same transaction never survives an invitation that lost validity mid-request.
 */
final class InvitationNoLongerPending extends RuntimeException
{
}
