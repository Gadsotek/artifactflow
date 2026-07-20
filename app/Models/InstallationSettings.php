<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $scope
 * @property int $max_markdown_bytes
 * @property int $max_html_bytes
 * @property int $artifact_max_bytes
 * @property int $max_workspace_storage_bytes
 * @property int $max_page_storage_bytes
 * @property int $max_page_versions
 * @property int $max_tags_per_page
 * @property bool $two_factor_required_for_system_admins
 * @property bool $two_factor_required_for_all_users
 * @property bool $realtime_enabled
 * @property string|null $updated_by_user_uid
 */
final class InstallationSettings extends Model
{
    use HasUlids;

    public const string SCOPE_INSTALLATION = 'installation';

    protected $primaryKey = 'uid';

    /**
     * Installation-wide limits and security flags are changed only through
     * explicit administration use cases.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_markdown_bytes' => 'integer',
            'max_html_bytes' => 'integer',
            'artifact_max_bytes' => 'integer',
            'max_workspace_storage_bytes' => 'integer',
            'max_page_storage_bytes' => 'integer',
            'max_page_versions' => 'integer',
            'max_tags_per_page' => 'integer',
            'two_factor_required_for_system_admins' => 'boolean',
            'two_factor_required_for_all_users' => 'boolean',
            'realtime_enabled' => 'boolean',
        ];
    }
}
