<?php

declare(strict_types=1);

namespace App\Domain\Identity;

enum WorkspaceRole: string
{
    case Reader = 'reader';
    case Editor = 'editor';
    case Admin = 'admin';

    public function rank(): int
    {
        return match ($this) {
            self::Reader => 1,
            self::Editor => 2,
            self::Admin => 3,
        };
    }

    public function canWritePages(): bool
    {
        return $this === self::Editor || $this === self::Admin;
    }
}
