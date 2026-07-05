<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\WorkspaceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $name
 * @property WorkspaceType $type
 * @property string|null $personal_owner_uid
 * @property bool $allow_editor_invites
 * @property bool $allow_editor_page_sharing
 * @property int $used_storage_bytes
 */
final class Workspace extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_editor_invites' => 'boolean',
            'allow_editor_page_sharing' => 'boolean',
            'type' => WorkspaceType::class,
            'used_storage_bytes' => 'integer',
        ];
    }
}
