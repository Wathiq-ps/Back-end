<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class AnalysisFinding extends Model
{
    use HasUuidPrimaryKey;

    const UPDATED_AT = null;

    protected $table = 'analysis_findings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'analysis_id', 'clause_id', 'clause_kind', 'kind', 'severity', 'title_ar',
        'title_en', 'description', 'suggested_text', 'citations', 'confidence', 'resolution',
        'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'confidence' => 'float',
            'resolved_at' => 'datetime',
        ];
    }
}
