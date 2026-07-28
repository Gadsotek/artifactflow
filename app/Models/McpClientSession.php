<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uid
 * @property string $session_id_hash
 * @property string $mcp_access_token_uid
 * @property string|null $client_reported_name
 * @property string|null $client_reported_version
 * @property string|null $protocol_version
 * @property \Carbon\CarbonImmutable $initialized_at
 */
final class McpClientSession extends Model
{
    use HasUlids;

    protected $primaryKey = 'uid';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'initialized_at' => 'immutable_datetime',
        ];
    }
}
