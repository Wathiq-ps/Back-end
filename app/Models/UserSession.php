<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A refresh-token record. The access token itself is a stateless JWT and is
 * never stored — see App\Services\JwtService.
 */
class UserSession extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'user_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id', 'user_id', 'session_family_id', 'token_hash', 'replaced_by_id',
        'device_name', 'ip_address', 'user_agent', 'issued_at',
        'last_used_at', 'expires_at', 'revoked_at', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
