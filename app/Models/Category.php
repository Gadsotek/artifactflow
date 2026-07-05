<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uid
 * @property string $workspace_uid
 * @property string $name
 * @property string $slug
 * @property string $created_by_user_uid
 * @property Workspace $workspace
 */
final class Category extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_uid',
        'name',
        'slug',
        'created_by_user_uid',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_uid', 'uid');
    }
}
