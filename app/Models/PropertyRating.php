<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class PropertyRating extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'property_ratings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'property_id', 'contract_id', 'rater_id', 'score', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function rater()
    {
        return $this->belongsTo(User::class, 'rater_id');
    }
}
