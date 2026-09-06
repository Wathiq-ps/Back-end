<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class OwnershipDocument extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ownership_documents';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'property_id', 'uploaded_by', 'type',
        'document_number', 'issued_on', 'path', 'checksum',
        'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
