<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * FR-6.13. Immutable once written — the database rejects any update or
 * delete (contract_versions_immutable). A change is a new version.
 */
class ContractVersion extends Model
{
    use HasUuidPrimaryKey;

    const UPDATED_AT = null;

    protected $table = 'contract_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'contract_id', 'version_no', 'body', 'body_format', 'content_hash',
        'author_type', 'author_id', 'ai_job_id', 'change_note',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function clauses()
    {
        return $this->hasMany(ContractClause::class)->orderBy('ordinal');
    }
}
