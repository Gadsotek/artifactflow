<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $uid
 * @property string $workspace_uid
 * @property string $owner_user_uid
 * @property string|null $parent_page_uid
 * @property string|null $category_uid
 * @property string|null $current_version_uid
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $search_vector
 * @property PageAccessMode $access_mode
 * @property int $metadata_revision
 * @property int $preview_access_revision
 * @property PageType $type
 * @property PageStatus $status
 * @property Category|null $category
 * @property PageVersion|null $currentVersion
 * @property User $owner
 * @property Workspace $workspace
 */
final class Page extends Model
{
    /** @use HasFactory<\Database\Factories\PageFactory> */
    use HasFactory;
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_page_uid',
        'category_uid',
        'title',
        'slug',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_mode' => PageAccessMode::class,
            'metadata_revision' => 'integer',
            'preview_access_revision' => 'integer',
            'type' => PageType::class,
            'status' => PageStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'page_tag', 'page_uid', 'tag_uid', 'uid', 'uid')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_uid', 'uid');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_uid', 'uid');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_uid', 'uid');
    }

    /**
     * @return BelongsTo<PageVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class, 'current_version_uid', 'uid');
    }

    /**
     * @return HasMany<PageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class, 'page_uid', 'uid');
    }

    /**
     * @return HasMany<PageAccessGrant, $this>
     */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(PageAccessGrant::class, 'page_uid', 'uid');
    }
}
