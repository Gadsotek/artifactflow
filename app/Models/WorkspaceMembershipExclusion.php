<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $workspace_uid
 * @property string $user_uid
 * @property string|null $excluded_by_user_uid
 */
final class WorkspaceMembershipExclusion extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * Security-sensitive authority records must be created only through an
     * explicit application handler using forceCreate().
     *
     * @var list<string>
     */
    protected $guarded = ['*'];
}
