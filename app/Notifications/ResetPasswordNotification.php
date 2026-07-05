<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use LogicException;

final class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
    }

    /**
     * @param mixed $notifiable
     */
    protected function resetUrl($notifiable): string
    {
        if (!$notifiable instanceof CanResetPasswordContract) {
            throw new LogicException('Password reset notifications require a resettable notifiable.');
        }

        $appUrl = config('app.url');

        if (!is_string($appUrl) || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            throw new LogicException('APP_URL must be a valid URL before password reset links can be sent.');
        }

        return rtrim($appUrl, '/') . route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);
    }
}
