<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $page_uid
 * @property PageAccessSubjectType $subject_type
 * @property string $subject_uid
 * @property WorkspaceRole $role
 * @property string $granted_by_user_uid
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PageAccessGrant extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'page_uid',
        'subject_type',
        'granted_by_user_uid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => PageAccessSubjectType::class,
            'role' => WorkspaceRole::class,
        ];
    }
}
