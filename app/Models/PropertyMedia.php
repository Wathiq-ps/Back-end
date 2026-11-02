<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PropertyMedia extends Model
{
    use HasUuidPrimaryKey;

    /** How long a photo URL from the API keeps working. */
    public const URL_TTL_MINUTES = 60;

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

    /**
     * Photos sit on the private disk, so this is a signed link to Laravel's
     * storage.local route rather than a public path: it opens the file until
     * it expires, and a draft's photos stay unreachable without it.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes(self::URL_TTL_MINUTES));
    }
}
