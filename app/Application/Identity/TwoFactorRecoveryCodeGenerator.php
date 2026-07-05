<?php

declare(strict_types=1);

namespace App\Application\Identity;

use Illuminate\Support\Facades\Hash;

final class TwoFactorRecoveryCodeGenerator
{
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * @return list<string>
     */
    public function generatePlainCodes(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateCode();
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public function hashCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => Hash::make($code), $codes);
    }

    public function normalize(string $code): string
    {
        return strtoupper(str_replace(' ', '', trim($code)));
    }

    private function generateCode(): string
    {
        $characters = '';
        $maxIndex = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < 10; $i++) {
            $characters .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return substr($characters, 0, 5) . '-' . substr($characters, 5);
    }
}
