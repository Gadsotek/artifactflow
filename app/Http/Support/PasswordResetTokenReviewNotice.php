<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class PasswordResetTokenReviewNotice
{
    public const string SESSION_KEY = 'token_review_notice';

    public function consume(Request $request, User $user): void
    {
        if ($user->password_reset_notice_pending_at === null) {
            return;
        }

        $activeTokenCount = DB::transaction(function () use ($user): ?int {
            $consumed = User::query()
                ->whereKey($user->uid)
                ->where('auth_revision', $user->auth_revision)
                ->whereNotNull('password_reset_notice_pending_at')
                ->update(['password_reset_notice_pending_at' => null]);

            if ($consumed !== 1) {
                return null;
            }

            return McpAccessToken::query()
                ->where('principal_user_uid', $user->uid)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count();
        });

        if ($activeTokenCount !== null && $activeTokenCount > 0) {
            $request->session()->flash(self::SESSION_KEY, $activeTokenCount);
        }
    }
}
