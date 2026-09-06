<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'tenants';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'slug', 'name_ar', 'name_en', 'status', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
