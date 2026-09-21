<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class PropertyRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'property_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'reference', 'property_id', 'requester_id', 'type',
        'offered_amount', 'offered_currency', 'term_start', 'term_end', 'message',
        'status', 'responded_by', 'responded_at', 'response_note', 'expires_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'offered_amount' => 'integer',
            'term_start' => 'date',
            'term_end' => 'date',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
