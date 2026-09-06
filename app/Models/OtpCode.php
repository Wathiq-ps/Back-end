<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'otp_codes';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id', 'user_id', 'channel', 'destination', 'code_hash',
        'expires_at', 'consumed_at', 'attempts', 'max_attempts', 'request_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
