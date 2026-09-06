<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class PropertyMedia extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'property_media';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id', 'tenant_id', 'property_id', 'disk', 'path', 'mime_type',
        'size_bytes', 'width', 'height', 'checksum', 'is_cover', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
