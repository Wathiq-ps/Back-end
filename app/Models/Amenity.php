<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'amenities';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id', 'code', 'name_ar', 'name_en', 'icon', 'is_active'];
}
