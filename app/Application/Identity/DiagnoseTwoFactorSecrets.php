<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final readonly class DiagnoseTwoFactorSecrets
{
    public function handle(): TwoFactorSecretDiagnosis
    {
        $checked = 0;
        $readable = 0;
        $unreadable = 0;

        foreach (User::query()->whereNotNull('two_factor_secret')->cursor() as $user) {
            $checked++;
            $rawSecret = $user->getRawOriginal('two_factor_secret');
            if (!is_string($rawSecret) || $rawSecret === '') {
                $unreadable++;

                continue;
            }

            try {
                $secret = Crypt::decryptString($rawSecret);
            } catch (DecryptException) {
                $unreadable++;

                continue;
            }

            if ($secret !== '') {
                $readable++;

                continue;
            }

            $unreadable++;
        }

        return new TwoFactorSecretDiagnosis(
            checked: $checked,
            readable: $readable,
            unreadable: $unreadable,
        );
    }
}
