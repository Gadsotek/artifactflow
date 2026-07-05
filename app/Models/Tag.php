<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $name
 * @property string $slug
 * @property string $created_by_user_uid
 */
final class Tag extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'created_by_user_uid',
    ];
}
