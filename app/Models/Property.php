<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    protected $table = 'properties';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'reference', 'owner_id',
        'title', 'description', 'type', 'listing_type', 'status',
        'price_amount', 'price_currency',
        'area_sqm', 'rooms', 'bathrooms', 'floor_number', 'total_floors', 'year_built', 'is_furnished',
        'location_id', 'address_line', 'city', 'district', 'building_number',
        'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'area_sqm' => 'decimal:2',
            'is_furnished' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function media()
    {
        return $this->hasMany(PropertyMedia::class)->orderBy('sort_order');
    }

    public function ownershipDocuments()
    {
        return $this->hasMany(OwnershipDocument::class);
    }

    public function amenities()
    {
        return $this->belongsToMany(Amenity::class, 'property_amenities');
    }
}
