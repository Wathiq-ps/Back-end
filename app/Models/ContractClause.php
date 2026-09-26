<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Immutable, like the version it belongs to (contract_clauses_immutable). */
class ContractClause extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $table = 'contract_clauses';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'contract_version_id', 'ordinal', 'kind', 'heading', 'body', 'is_ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'is_ai_generated' => 'boolean',
        ];
    }
}
