<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\DomainRuleViolation;
use App\Models\WorkspaceInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RegisterWorkspaceInvitationUser
{
    public function __construct(
        private CreateUser $createUser,
        private AcceptWorkspaceInvitation $acceptInvitation,
    ) {
    }

    /**
     * @throws InvitationNoLongerPending
     * @throws DomainRuleViolation
     */
    public function handle(RegisterWorkspaceInvitationUserCommand $command): WorkspaceInvitationRegistrationResult
    {
        return DB::transaction(function () use ($command): WorkspaceInvitationRegistrationResult {
            $invitation = WorkspaceInvitation::query()
                ->whereKey($command->invitationUid)
                ->lockForUpdate()
                ->first();

            if (
                !$invitation instanceof WorkspaceInvitation
                || !$invitation->isPending()
                || !hash_equals($invitation->token, $command->presentedToken)
            ) {
                throw new InvitationNoLongerPending();
            }

            // No actor: possession of the still-pending invitation token is the
            // authorization boundary. The account email comes only from the locked
            // invitation row and can never be supplied by the public request.
            $user = $this->createUser->handle(
                name: $command->name,
                email: $invitation->invited_email,
                password: $command->password,
            );

            try {
                $membership = $this->acceptInvitation->handle($user, $invitation);
            } catch (AuthorizationException | DomainRuleViolation) {
                // Account creation and acceptance share this outer transaction, so
                // any late invitation failure rolls the new account back as well.
                throw new InvitationNoLongerPending();
            }

            return new WorkspaceInvitationRegistrationResult($user, $membership);
        });
    }
}
