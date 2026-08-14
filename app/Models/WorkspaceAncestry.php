<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model for the closure-table index derived from
 * workspaces.parent_workspace_uid.
 *
 * @property string $ancestor_workspace_uid
 * @property string $descendant_workspace_uid
 * @property int $depth
 */
final class WorkspaceAncestry extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'workspace_ancestry';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
        ];
    }
}
