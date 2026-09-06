<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class IdentityDocument extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'identity_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'user_id', 'type', 'document_number',
        'issuing_country_id', 'front_path', 'back_path', 'selfie_path',
        'expires_on', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
