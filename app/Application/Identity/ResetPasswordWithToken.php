<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class ResetPasswordWithToken
{
    public function __construct(
        private PasswordBroker $passwordBroker,
        private ResetUserPassword $resetUserPassword,
    ) {
    }

    public function handle(
        string $email,
        #[\SensitiveParameter]
        string $token,
        #[\SensitiveParameter]
        string $newPassword,
    ): string {
        return DB::transaction(function () use ($email, $newPassword, $token): string {
            // The framework validates a reset token before invoking the password-change
            // callback and deletes it afterwards. Serialise both operations on this row
            // so two requests cannot validate the same bearer token concurrently.
            DB::table($this->passwordResetTable())
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            $status = $this->passwordBroker->reset(
                [
                    'email' => $email,
                    'token' => $token,
                    'password' => $newPassword,
                    'password_confirmation' => $newPassword,
                ],
                function (CanResetPasswordContract $user, string $password): void {
                    if (!$user instanceof User) {
                        throw new LogicException('Password reset broker returned an unsupported user model.');
                    }

                    $this->resetUserPassword->handle($user, $password);
                },
            );

            if (!is_string($status)) {
                throw new LogicException('Password reset broker returned an unsupported status.');
            }

            return $status;
        });
    }

    private function passwordResetTable(): string
    {
        $broker = config('auth.defaults.passwords');
        if (!is_string($broker) || trim($broker) === '') {
            throw new LogicException('The default password broker is not configured.');
        }

        $table = config(sprintf('auth.passwords.%s.table', $broker));
        if (!is_string($table) || trim($table) === '') {
            throw new LogicException('The password reset token table is not configured.');
        }

        return $table;
    }
}
