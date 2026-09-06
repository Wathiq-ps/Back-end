<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'roles';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id', 'code', 'name_ar', 'name_en', 'is_system'];
}
