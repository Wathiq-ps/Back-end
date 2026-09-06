<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class TenantMembership extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'tenant_memberships';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id', 'tenant_id', 'user_id', 'role_id', 'status',
        'granted_by', 'granted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
